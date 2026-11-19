<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Budget\SpendGate;
use IndianOpinion\PostEmbeddings\Health\Exposure;
use IndianOpinion\PostEmbeddings\Index\DrainLock;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Provider\HttpEmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\RejectedInputError;
use IndianOpinion\PostEmbeddings\Provider\TransientProviderError;
use IndianOpinion\PostEmbeddings\Query\Search;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Review fixes: atomic cap claims, the spend-meter call protocol, the
 * backfill run cap and cursor, the adapter URL guard, the sidecar directory
 * and exposure probe failing closed, and the key bound to its host.
 */
final class HardeningTest extends TestCase
{
    private function backfill(int $cap, int $batch = 64, bool $resume = false): int
    {
        return $this->plugin->backfill->run(
            ['cap' => $cap, 'types' => ['post'], 'order' => 'asc', 'since' => null, 'batch' => $batch, 'dry_run' => false, 'resume' => $resume],
            static function (string $l): void {
            }
        );
    }

    /** @return list<int> */
    private function archive(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = $this->post(['post_title' => 'Archive ' . $i])->ID;
        }

        return $ids;
    }

    public function testClaimsNeverOvershootAndAreGivenBackWhenRefused(): void
    {
        $this->settings(['daily_post_cap' => 10, 'daily_token_cap' => 100, 'daily_query_cap' => 1]);
        $c = $this->plugin->counters;
        self::assertTrue($c->claimIndex(6, 50));
        self::assertFalse($c->claimIndex(5, 10), 'would cross the post cap');
        self::assertFalse($c->claimIndex(1, 51), 'would cross the token cap');
        self::assertSame(6, $c->usage()['index_posts'], 'a refused claim is given back');
        self::assertSame(50, $c->usage()['index_tokens']);
        $c->releaseIndex(6, 50);
        self::assertSame(0, $c->usage()['index_posts']);
        self::assertTrue($c->claimQuery());
        self::assertFalse($c->claimQuery());
        self::assertSame(1, $c->usage()['queries']);
    }

    public function testSpendMeterReleaseOnlyWithAReservationAndUnbilledFailuresAreGivenBack(): void
    {
        $records = [];
        $releases = [];
        add_action('spend_meter_record', static function (array $call) use (&$records): void {
            $records[] = $call;
        });
        add_action('spend_meter_release', static function (string $id) use (&$releases): void {
            $releases[] = $id;
        });
        add_filter('spend_meter_pre_spend', static fn ($d, array $i): array => ['allowed' => true, 'reservation_id' => null]);
        $p = $this->post(['post_title' => 'Refused connection story']);
        $this->plugin->onSave($p->ID, $p);
        $this->provider->script = [new TransientProviderError('refused')];
        $this->plugin->drain->run();
        self::assertSame('error_unbilled', $records[0]['status']);
        self::assertNull($records[0]['reservation_id']);
        self::assertSame(0, $this->plugin->counters->usage()['index_posts'], 'a refused connection is given back');

        $this->clock->advance(86400);
        $this->provider->script = [new TransientProviderError('timed out', null, true)];
        $this->plugin->drain->run();
        self::assertSame('error_billed', $records[1]['status']);
        self::assertGreaterThan(0, $records[1]['input_tokens']);
        self::assertSame(1, $this->plugin->counters->usage()['index_posts'], 'a possibly billed timeout stays counted');

        $this->settings(['daily_post_cap' => 1]);
        $this->clock->advance(86400);
        $this->plugin->counters->addIndex(1, 0);
        $this->plugin->drain->run();
        self::assertSame([], $releases, 'no reservation id, nothing to release');
        self::assertCount(2, $records);
    }

    public function testBackfillRunCapCountsEveryPostSentEvenWhenRejected(): void
    {
        $this->archive(12);
        $this->provider->script = array_fill(0, 100, new RejectedInputError('bad', 400));
        self::assertSame(0, $this->backfill(5, 1));
        self::assertCount(5, $this->provider->sent(), 'unbilled rejections still count against --cap');
    }

    public function testBackfillStoppedByTheMeterResumesWithoutLosingPosts(): void
    {
        $ids = $this->archive(4);
        add_filter('spend_meter_pre_spend', static fn ($d, array $i): array => ['allowed' => false, 'reason' => 'cap_site_day']);
        self::assertSame(0, $this->backfill(10, 2));
        self::assertSame([], $this->provider->calls);
        remove_all_filters('spend_meter_pre_spend');
        self::assertSame(0, $this->backfill(10, 2, true));
        foreach ($ids as $id) {
            self::assertSame(IndexRow::OK, $this->rowOf($id)?->status, 'post ' . $id . ' was not skipped');
        }
    }

    public function testBackfillDoesNotResendTextThatAlreadyFailed(): void
    {
        [$id] = $this->archive(1);
        $this->provider->script = [new RejectedInputError('bad', 400)];
        $this->backfill(10);
        self::assertSame(IndexRow::ERROR, $this->rowOf($id)?->status);
        $this->provider->calls = [];
        $this->backfill(10);
        self::assertSame([], $this->provider->calls, 'only retry --errors sends it again');
    }

    public function testAdapterUrlRefusesPrivateTargetsAndUsesTheSafeHttpApi(): void
    {
        foreach (
            [
                'https://10.0.0.5/v1', 'https://192.168.1.2', 'https://169.254.169.254/latest', 'https://[::1]/v1',
                'https://0.0.0.0', 'https://api.example.test/v1?x=1', 'https://api.example.test/v1#f',
                'https://api.example.test\\@10.0.0.1/', 'https://bad_host.example.test', 'http://localhost.evil.test',
            ] as $bad
        ) {
            self::assertNotNull(HttpEmbeddingProvider::validateBaseUrl($bad), $bad);
        }
        self::assertNull(HttpEmbeddingProvider::validateBaseUrl('https://localhost:8443/v1'));
        self::assertNull(HttpEmbeddingProvider::validateBaseUrl('https://8.8.8.8/v1'));

        \WpStub::$http = static fn (): array => ['response' => ['code' => 200], 'body' => '{"data":[{"index":0,"embedding":[1,0]}]}'];
        (new HttpEmbeddingProvider('https://api.example.test/v1', 'm', 2, 'k'))->embed(['a']);
        self::assertTrue(\WpStub::$requests[0][1]['reject_unsafe_urls'] ?? false, 'remote hosts go through the safe-URL check');
        (new HttpEmbeddingProvider('http://127.0.0.1:11434/v1', 'm', 2, ''))->embed(['a']);
        self::assertArrayNotHasKey('reject_unsafe_urls', \WpStub::$requests[1][1], 'the local model is reached directly');
    }

    public function testStoredKeyIsClearedWhenTheBaseUrlHostChanges(): void
    {
        $this->plugin->settings->save(['base_url' => 'https://api.example.test/v1', 'api_key' => 'k-1'], $this->plugin->state);
        $errors = $this->plugin->settings->save(['base_url' => 'https://api.example.test/v2'], $this->plugin->state);
        self::assertSame([], $errors);
        self::assertSame('k-1', $this->plugin->settings->apiKey(), 'same host keeps the key');
        $errors = $this->plugin->settings->save(['base_url' => 'https://other.example.test/v1'], $this->plugin->state);
        self::assertStringContainsString('cleared', implode(' ', $errors));
        self::assertSame('', $this->plugin->settings->apiKey());
        $this->plugin->settings->save(['base_url' => 'https://third.example.test/v1', 'api_key' => 'k-3'], $this->plugin->state);
        self::assertSame('k-3', $this->plugin->settings->apiKey(), 'a new key with the new host is kept');
    }

    public function testRelativeSidecarDirectoryFailsClosed(): void
    {
        add_filter('post_embeddings_sidecar_dir', static fn (): string => 'relative/vectors');
        self::assertSame('directory_not_absolute', $this->plugin->directory->protect());
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        self::assertNull($this->plugin->sidecar->rebuild($this->key(), 8));
        self::assertSame('directory_not_absolute', $this->plugin->state->get('sidecar_error'));
        self::assertFalse(is_dir('relative/vectors'));
    }

    public function testExposureIsUnverifiedWithoutAFileOrOnARedirect(): void
    {
        $dir = WP_CONTENT_DIR . '/not-yet-created-' . getmypid();
        add_filter('post_embeddings_sidecar_dir', static fn (): string => $dir);
        $exposure = new Exposure($this->plugin->directory, $this->plugin->state, $this->clock);
        \WpStub::$http = static fn (): array => ['response' => ['code' => 404], 'body' => ''];
        self::assertSame('unknown', $exposure->check(true), 'a directory under the content dir that does not exist yet is not "not public"');
        self::assertSame([], \WpStub::$requests, 'nothing to ask for');

        self::assertNull($this->plugin->directory->protect());
        \WpStub::$http = static fn (): array => ['response' => ['code' => 301], 'body' => ''];
        self::assertSame('unknown', $exposure->check(true), 'a redirect proves nothing');
        \WpStub::$http = static fn (): array => ['response' => ['code' => 403], 'body' => ''];
        self::assertSame('blocked', $exposure->check(true));
        foreach (array_keys(Directory::denyFiles()) as $f) {
            unlink($dir . '/' . $f);
        }
        rmdir($dir);
    }

    public function testOversizedRawQueryIsRefusedBeforeAnyWork(): void
    {
        $o = $this->plugin->search->search(str_repeat('a ', Search::MAX_RAW_BYTES));
        self::assertSame('invalid', $o->mode);
        self::assertSame([], $this->provider->calls);
        $html = $this->plugin->searchRenderer->render([], ['semantic' => str_repeat('x', 10000)], []);
        self::assertStringNotContainsString(str_repeat('x', Search::MAX_LEN + 1), $html, 'the field never echoes more than it accepts');
    }

    public function testBackfillWaitsForARunningDrainAndHoldsTheLockItself(): void
    {
        $this->archive(2);
        add_option(DrainLock::OPTION, ['token' => 'drain', 'expires' => $this->clock->now() + 60], '', false);
        self::assertSame(1, $this->backfill(10), 'a running drain may be sending the same rows');
        self::assertSame([], $this->provider->calls);

        delete_option(DrainLock::OPTION);
        $seen = [];
        add_filter('spend_meter_pre_spend', function ($d) use (&$seen) {
            $seen[] = get_option(DrainLock::OPTION);

            return $d;
        }, 10, 2);
        self::assertSame(0, $this->backfill(10));
        self::assertIsArray($seen[0] ?? null, 'the lock is held while the backfill sends');
        self::assertFalse(get_option(DrainLock::OPTION, false), 'and released afterwards');
    }

    public function testRedactionSeesACredentialThatStraddlesTheCut(): void
    {
        $body = str_repeat('x ', 140) . '"api_key": "abcdefghijklmnopqrstuvwxyz0123456789"';
        $out = Logger::redact($body, 300);
        self::assertLessThanOrEqual(300, strlen($out));
        self::assertStringNotContainsString('abcdefghij', $out);
    }

    #[RunInSeparateProcess]
    public function testOlderSpendMeterContractIsTreatedAsAbsent(): void
    {
        define('SPEND_METER_API', SpendGate::API - 1);
        add_filter('spend_meter_pre_spend', static fn (): array => ['allowed' => false, 'reason' => 'kill_switch']);
        $r = (new SpendGate())->reserve('index', 'test', 'm', 10);
        self::assertTrue($r->allowed);
        self::assertFalse($r->metered);
    }

    #[RunInSeparateProcess]
    public function testNewerSpendMeterContractRefusesToSpend(): void
    {
        define('SPEND_METER_API', SpendGate::API + 1);
        $r = (new SpendGate())->reserve('index', 'test', 'm', 10);
        self::assertFalse($r->allowed);
        self::assertSame('spend_meter_api', $r->reason);
    }
}
