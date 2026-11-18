<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Cli\Runner;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingResult;
use IndianOpinion\PostEmbeddings\Provider\HttpEmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\InputTooLongError;
use IndianOpinion\PostEmbeddings\Provider\RejectedInputError;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;

/** Cases 20 to 26. */
final class ProviderFailureTest extends TestCase
{
    private const KEY = 'sk-test-0123456789abcdefghijklmnop';

    private function http(): HttpEmbeddingProvider
    {
        $p = new HttpEmbeddingProvider('https://api.example.test/v1', 'embed-small', 4, self::KEY, 8000, 16);
        $this->useProvider($p);

        return $p;
    }

    /** @param array<string, string> $headers */
    private static function respond(int $code, string $body, array $headers = []): \Closure
    {
        return static fn (): array => ['response' => ['code' => $code], 'body' => $body, 'headers' => $headers];
    }

    private function savePending(string $title = 'Story'): int
    {
        $p = $this->post(['post_title' => $title]);
        $this->plugin->onSave($p->ID, $p);

        return $p->ID;
    }

    /** T19 */
    public function testRateLimitHonoursRetryAfterAndGivesUpAfterMaxAttempts(): void
    {
        $this->http();
        $id = $this->savePending();
        \WpStub::$http = self::respond(429, '{"error":"slow down"}', ['retry-after' => '7200']);
        $r = $this->plugin->drain->run();
        self::assertSame('transient', $r['result']);
        $row = $this->rowOf($id);
        self::assertSame(IndexRow::PENDING, $row?->status);
        self::assertSame(1, $row->attempts);
        self::assertSame($this->clock->now() + 7200, $row->nextAttemptAt);

        \WpStub::$http = self::respond(503, 'busy');
        $this->settings(['max_attempts' => 3]);
        $delays = [];
        for ($i = 0; $i < 5; $i++) {
            $this->clock->advance(86400);
            $this->plugin->drain->run();
            $row = $this->rowOf($id);
            if ($row?->status === IndexRow::PENDING) {
                $delays[] = (int) $row->nextAttemptAt - $this->clock->now();
            }
        }
        self::assertSame([900], $delays, 'second failure backs off 15 minutes');
        self::assertSame(IndexRow::ERROR, $this->rowOf($id)?->status);
        self::assertSame('gave_up', $this->rowOf($id)?->errorCode);
    }

    /** T20 */
    public function testAuthFailurePausesWithoutRaisingAttemptsAndSavingSettingsClearsIt(): void
    {
        $this->http();
        $id = $this->savePending();
        \WpStub::$http = self::respond(401, '{"error":"bad key"}');
        $this->plugin->drain->run();
        self::assertSame('paused_auth', $this->plugin->state->paused());
        self::assertSame(0, $this->rowOf($id)?->attempts);
        self::assertSame(IndexRow::PENDING, $this->rowOf($id)?->status);
        self::assertCount(1, \WpStub::fired('post_embeddings_paused'));
        self::assertStringContainsString('paused', $this->plugin->screen->noticeHtml());

        $requests = count(\WpStub::$requests);
        self::assertSame('paused', $this->plugin->drain->run()['result']);
        self::assertCount($requests, \WpStub::$requests, 'nothing sent while paused');

        $this->plugin->settings->save(['api_key' => 'new-key-value'], $this->plugin->state);
        self::assertNull($this->plugin->state->paused());
        self::assertSame('', $this->plugin->screen->noticeHtml());
    }

    /** T21 */
    public function testInputTooLongRetriesOnceAtHalfTheLimitThenGivesUp(): void
    {
        $this->provider->maxChars = 1000;
        $id = $this->savePending(str_repeat('word ', 300));
        $this->provider->script = [new InputTooLongError('long'), new InputTooLongError('still long')];
        $this->plugin->drain->run();
        self::assertCount(2, $this->provider->calls);
        self::assertLessThanOrEqual(1000, mb_strlen($this->provider->calls[0][0]));
        self::assertLessThanOrEqual(500, mb_strlen($this->provider->calls[1][0]));
        self::assertGreaterThan(450, mb_strlen($this->provider->calls[1][0]));
        self::assertSame('too_long', $this->rowOf($id)?->errorCode);

        $id2 = $this->savePending(str_repeat('more ', 300));
        $this->provider->script = [new InputTooLongError('long')];
        $this->plugin->drain->run();
        self::assertSame(IndexRow::OK, $this->rowOf($id2)?->status, 'the half-length retry succeeded');
    }

    /** T22 */
    public function testOther4xxRetriesSinglyAndOnlyTheBadPostFails(): void
    {
        $ids = [$this->savePending('Alpha'), $this->savePending('Bad'), $this->savePending('Gamma')];
        $this->provider->script = [
            new RejectedInputError('bad batch', 400),
            null,
            new RejectedInputError('bad input', 400),
            null,
        ];
        $this->plugin->drain->run();
        self::assertCount(4, $this->provider->calls);
        self::assertSame(IndexRow::OK, $this->rowOf($ids[0])?->status);
        self::assertSame('rejected_400', $this->rowOf($ids[1])?->errorCode);
        self::assertSame(IndexRow::OK, $this->rowOf($ids[2])?->status);
    }

    /** T23 */
    public function testNonJsonBodyIsTransientAndTheLoggedExcerptHasNoKey(): void
    {
        $this->http();
        $id = $this->savePending();
        $logged = [];
        add_action('post_embeddings_log', static function (string $level, string $code, array $ctx) use (&$logged): void {
            $logged[] = [$code, $ctx];
        });
        \WpStub::$http = self::respond(200, '<html>Gateway error. Authorization: Bearer ' . self::KEY . ' api_key=' . self::KEY . '</html>');
        $this->plugin->drain->run();
        self::assertSame(IndexRow::PENDING, $this->rowOf($id)?->status);
        self::assertSame(1, $this->rowOf($id)?->attempts);
        $all = json_encode($logged) . implode("\n", $this->logLines);
        self::assertStringContainsString('provider_malformed', $all);
        self::assertStringContainsString('Gateway error', $all);
        self::assertStringNotContainsString(self::KEY, $all);
        self::assertStringNotContainsString('0123456789abcdef', $all);
    }

    /** T24 */
    public function testTimeoutAfterSendCountsAgainstTheCap(): void
    {
        $this->http();
        $this->savePending();
        \WpStub::$http = static fn (): \WP_Error => new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 30001 milliseconds');
        $this->plugin->drain->run();
        self::assertSame(1, $this->plugin->counters->usage()['index_posts']);
        self::assertGreaterThan(0, $this->plugin->counters->usage()['index_tokens']);

        \WpStub::$http = static fn (): \WP_Error => new \WP_Error('http_request_failed', 'cURL error 7: Failed to connect');
        $this->clock->advance(86400 * 2);
        $this->plugin->drain->run();
        self::assertSame(0, $this->plugin->counters->usage()['index_posts'], 'a refused connection was never billed');
    }

    /** T25 */
    public function testRetryErrorsMovesErrorRowsBackAndNothingElseTouchesThem(): void
    {
        $id = $this->savePending();
        $this->provider->script = [new EmbeddingResult([0 => [1.0]])];
        $this->plugin->drain->run();
        $row = $this->rowOf($id);
        self::assertSame(IndexRow::ERROR, $row?->status);

        $post = get_post($id);
        self::assertNotNull($post);
        $this->plugin->onSave($id, $post);
        $this->clock->advance(86400);
        $this->plugin->drain->run();
        self::assertSame(IndexRow::ERROR, $this->rowOf($id)?->status, 'saves and drains leave error rows alone');

        $out = [];
        $runner = new Runner($this->plugin, static function (string $l) use (&$out): void {
            $out[] = $l;
        });
        self::assertSame(1, $runner->run('retry', [], []));
        self::assertSame(0, $runner->run('retry', [], ['errors' => true]));
        $row = $this->rowOf($id);
        self::assertSame(IndexRow::PENDING, $row?->status);
        self::assertSame(0, $row->attempts);
        self::assertNull($row->errorCode);
        $this->plugin->drain->run();
        self::assertSame(IndexRow::OK, $this->rowOf($id)?->status);
    }
}
