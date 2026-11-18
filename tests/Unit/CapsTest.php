<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 27 to 34. */
final class CapsTest extends TestCase
{
    /** @return list<int> */
    private function pending(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $this->post(['post_title' => 'Item ' . $i]);
            $this->plugin->onSave($p->ID, $p);
            $ids[] = $p->ID;
        }

        return $ids;
    }

    private function backfill(int $cap): int
    {
        return $this->plugin->backfill->run(
            ['cap' => $cap, 'types' => ['post'], 'order' => 'asc', 'since' => null, 'batch' => 64, 'dry_run' => false, 'resume' => false],
            static function (string $l): void {
            }
        );
    }

    /** T26 */
    public function testBatchIsTrimmedToTheCapsLeft(): void
    {
        $this->settings(['batch_size' => 16, 'drain_max_batches' => 4]);
        $this->pending(20);
        $this->plugin->counters->addIndex(495, 0);
        $r = $this->plugin->drain->run();
        self::assertCount(1, $this->provider->calls);
        self::assertCount(5, $this->provider->calls[0]);
        self::assertSame('cap_posts', $r['result']);
        self::assertSame(500, $this->plugin->counters->usage()['index_posts']);

        $this->clock->advance(86400);
        $perPost = $this->plugin->tokens->estimate($this->plugin->text->build(get_post(10) ?? $this->post()));
        $this->settings(['daily_token_cap' => $perPost * 3 + 1]);
        $this->plugin->drain->run();
        self::assertCount(3, $this->provider->calls[1], 'the token estimate trims too');
    }

    /** T27 */
    public function testSearchCountsOnlyAgainstTheQueryCapAndCachedQueriesCountNothing(): void
    {
        $this->plugin->search->search('fuel prices rise');
        $u = $this->plugin->counters->usage();
        self::assertSame(1, $u['queries']);
        self::assertSame(0, $u['index_posts']);
        $this->plugin->search->search('Fuel   prices rise');
        self::assertSame(1, $this->plugin->counters->usage()['queries']);
        self::assertCount(1, $this->provider->calls);
    }

    /** T28 */
    public function testCountersResetWhenTheUtcDateChangesWithoutCron(): void
    {
        $this->settings(['daily_post_cap' => 2]);
        $this->plugin->counters->addIndex(2, 10);
        $this->plugin->counters->addQuery();
        self::assertSame(0, $this->plugin->counters->postsLeft());
        $this->clock->now = (int) strtotime('tomorrow 00:00:01 UTC', $this->clock->now);
        self::assertSame(2, $this->plugin->counters->postsLeft());
        self::assertSame(0, $this->plugin->counters->usage()['queries']);
        self::assertSame([], \WpStub::$events);
    }

    /** T30 */
    public function testSearchDisabledStopsQueryEmbeddingsButNotIndexing(): void
    {
        $this->settings(['search_enabled' => false]);
        $o = $this->plugin->search->search('fuel prices rise');
        self::assertSame('keyword', $o->mode);
        self::assertSame('search_disabled', $o->reason);
        self::assertSame([], $this->provider->calls);
        [$id] = $this->pending(1);
        $this->plugin->drain->run();
        self::assertSame(IndexRow::OK, $this->rowOf($id)?->status);
    }

    /** T31 */
    public function testBackfillCapAndDailyCapTheSmallerApplies(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->post(['post_title' => 'Archive ' . $i]);
        }
        $this->plugin->counters->addIndex(460, 0);
        self::assertSame(0, $this->backfill(100));
        self::assertCount(40, $this->provider->sent());
    }

    /** T32 */
    public function testSpendMeterDenyAllowAbortAndAbsent(): void
    {
        $records = [];
        $releases = [];
        add_action('spend_meter_record', static function ($id, array $data) use (&$records): void {
            $records[] = [$id, $data];
        });
        add_action('spend_meter_release', static function ($id) use (&$releases): void {
            $releases[] = $id;
        });
        [$a] = $this->pending(1);

        $decision = ['allowed' => false];
        $requests = [];
        add_filter('spend_meter_pre_spend', static function ($d, array $req) use (&$decision, &$requests) {
            $requests[] = $req;

            return $decision;
        });
        $this->plugin->drain->run();
        self::assertSame([], $this->provider->calls);
        self::assertSame('post-embeddings.index', $requests[0]['purpose']);
        self::assertSame('post-embeddings', $requests[0]['caller']);
        self::assertSame('test', $requests[0]['provider']);
        self::assertSame('test-model', $requests[0]['model']);
        self::assertGreaterThan(0, $requests[0]['estimated_input_tokens']);

        $decision = ['allowed' => 'yes'];
        $this->plugin->drain->run();
        self::assertSame([], $this->provider->calls, 'allowed must be exactly true');

        $decision = ['allowed' => true, 'reservation_id' => 'r1'];
        $this->plugin->drain->run();
        self::assertCount(1, $this->provider->calls);
        self::assertSame('r1', $records[0][0]);
        self::assertSame('ok', $records[0][1]['status']);
        self::assertCount(1, $records);
        self::assertSame(IndexRow::OK, $this->rowOf($a)?->status);

        $this->pending(1);
        $decision = ['allowed' => true, 'reservation_id' => 'r2'];
        $plugin = $this->plugin;
        remove_all_filters('spend_meter_pre_spend');
        add_filter('spend_meter_pre_spend', static function () use ($plugin, &$decision) {
            $plugin->counters->addIndex(1000, 0);

            return $decision;
        });
        $this->plugin->drain->run();
        self::assertSame(['r2'], $releases);
        self::assertCount(1, $this->provider->calls);

        remove_all_filters('spend_meter_pre_spend');
        add_filter('spend_meter_pre_spend', static fn ($d) => null);
        $this->clock->advance(86400);
        $this->plugin->drain->run();
        self::assertCount(2, $this->provider->calls, 'a null meter leaves the plugin caps alone in charge');
        self::assertCount(1, $records, 'nothing recorded without a meter');
    }

    /** T33 */
    public function testCapHitLogsOnceAndFiresOnceADay(): void
    {
        $this->settings(['daily_post_cap' => 0]);
        $this->pending(1);
        for ($i = 0; $i < 50; $i++) {
            $this->plugin->drain->run();
            $this->clock->advance(60);
        }
        self::assertCount(1, \WpStub::fired('post_embeddings_cap_reached'));
        self::assertSame(['posts', 0], \WpStub::fired('post_embeddings_cap_reached')[0][1]);
        self::assertCount(1, array_filter($this->logLines, static fn (string $l): bool => str_contains($l, 'cap_reached_posts')));
        $this->clock->now = (int) strtotime('tomorrow 01:00 UTC', $this->clock->now);
        $this->plugin->drain->run();
        self::assertCount(2, \WpStub::fired('post_embeddings_cap_reached'));
    }
}
