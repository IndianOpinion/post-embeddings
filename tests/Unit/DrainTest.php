<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PostEmbeddings\Provider\EmbeddingResult;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/** Cases 12 to 19. */
final class DrainTest extends TestCase
{
    /** @return list<int> post ids, saved as pending, oldest first */
    private function pending(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $p = $this->post(['post_title' => 'Story ' . $i]);
            $this->plugin->onSave($p->ID, $p);
            $this->clock->advance(1);
            $ids[] = $p->ID;
        }

        return $ids;
    }

    /** T11 */
    public function testDrainSendsOldestFirstInBatchesOfBatchSize(): void
    {
        $this->settings(['batch_size' => 3, 'drain_max_batches' => 1]);
        $ids = $this->pending(5);
        // Touch the first post so it becomes the newest.
        $first = $this->rowOf($ids[0]);
        self::assertNotNull($first);
        $first->updatedAt = $this->clock->now() + 100;
        $this->store->save($first);

        $this->plugin->drain->run();
        self::assertCount(1, $this->provider->calls);
        self::assertCount(3, $this->provider->calls[0]);
        self::assertStringStartsWith('Story 1', $this->provider->calls[0][0]);
        self::assertStringStartsWith('Story 3', $this->provider->calls[0][2]);

        $this->provider->batch = 2;
        $this->plugin->drain->run();
        self::assertCount(2, $this->provider->calls[1], 'batch size clamped to the adapter maxBatch');
    }

    /** T12 */
    public function testKillSwitchCapAndSpendMeterStopTheDrain(): void
    {
        $ids = $this->pending(3);

        $this->settings(['enabled' => false]);
        self::assertSame('kill_switch', $this->plugin->drain->run()['result']);
        $this->settings(['enabled' => true, 'daily_post_cap' => 0]);
        self::assertSame('cap_posts', $this->plugin->drain->run()['result']);
        $this->settings(['daily_post_cap' => 500]);
        add_filter('spend_meter_pre_spend', static fn ($d, array $req): array => ['allowed' => false, 'reason' => 'budget']);
        self::assertSame('spend_meter', $this->plugin->drain->run()['result']);

        self::assertSame([], $this->provider->calls);
        foreach ($ids as $id) {
            self::assertSame(IndexRow::PENDING, $this->rowOf($id)?->status);
        }
    }

    /** T13 */
    public function testDrainStopsAtMaxBatchesAndAtTheWallTimeLimit(): void
    {
        $this->settings(['batch_size' => 1, 'drain_max_batches' => 2]);
        $this->pending(5);
        $r = $this->plugin->drain->run();
        self::assertSame(2, $r['batches']);
        self::assertCount(2, $this->provider->calls);

        $this->settings(['drain_max_batches' => 50]);
        $this->clock->tick = 7.0;
        $r = $this->plugin->drain->run();
        self::assertSame('time_limit', $r['result']);
        self::assertLessThanOrEqual(3, $r['batches']);
        self::assertGreaterThan(0, $r['batches']);
    }

    /** T14 */
    public function testOverlappingDrainsSendEachPostOnceAndAnExpiredLockIsTakenOver(): void
    {
        $this->settings(['batch_size' => 2, 'drain_max_batches' => 5]);
        $this->pending(4);
        $inner = null;
        $this->provider->during = function () use (&$inner): void {
            if ($inner === null) {
                $inner = $this->plugin->drain->run();
            }
        };
        $this->plugin->drain->run();
        self::assertSame('locked', $inner['result'] ?? null);
        $sent = $this->provider->sent();
        self::assertCount(4, $sent);
        self::assertCount(4, array_unique($sent));

        add_option('post_embeddings_drain_lock', ['token' => 'stale', 'expires' => $this->clock->now() - 1]);
        $this->pending(1);
        $this->provider->during = null;
        self::assertSame('ok', $this->plugin->drain->run()['result']);
        self::assertFalse(get_option('post_embeddings_drain_lock'), 'released after the run');

        add_option('post_embeddings_drain_lock', ['token' => 'live', 'expires' => $this->clock->now() + 300]);
        self::assertSame('locked', $this->plugin->drain->run()['result']);
    }

    /** T15 */
    public function testBadVectorsAreNotStoredAndGetTheMatchingCode(): void
    {
        $ids = $this->pending(5);
        $good = array_fill(0, 8, 0.5);
        $this->provider->script[] = new EmbeddingResult([
            0 => array_fill(0, 7, 0.5),
            1 => [NAN, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1],
            2 => ['0.1', 0.1, 0.1, 0.1, 0.1, 0.1, 0.1, 0.1],
            3 => array_fill(0, 8, 0.0),
            4 => $good,
        ]);
        $this->plugin->drain->run();
        $codes = array_map(fn (int $id): ?string => $this->rowOf($id)?->errorCode, $ids);
        self::assertSame(['bad_vector_length', 'bad_vector_non_finite', 'bad_vector_not_number', 'bad_vector_zero_norm', null], $codes);
        self::assertSame(IndexRow::ERROR, $this->rowOf($ids[0])?->status);
        self::assertSame(IndexRow::OK, $this->rowOf($ids[4])?->status);
        self::assertCount(1, array_filter($this->logLines, static fn (string $l): bool => str_contains($l, 'bad_vector_length')));
        self::assertCount(4, \WpStub::fired('post_embeddings_failed'));

        $this->plugin->drain->run();
        self::assertCount(1, $this->provider->calls, 'bad vectors are not retried automatically');
    }

    /** T16 */
    public function testStoredVectorIsUnitLengthWithModelKeyDimHashAndTime(): void
    {
        [$id] = $this->pending(1);
        $this->provider->script[] = new EmbeddingResult([0 => [3.0, 4.0, 0, 0, 0, 0, 0, 0]]);
        $this->plugin->drain->run();
        $row = $this->rowOf($id);
        self::assertNotNull($row);
        $v = VectorMath::unpack($row->vector, 8);
        self::assertNotNull($v);
        self::assertSame(32, strlen((string) $row->vector));
        self::assertEqualsWithDelta(1.0, sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $v))), 1e-6);
        self::assertEqualsWithDelta(0.6, $v[0], 1e-6);
        self::assertSame('test:test-model:8', $row->modelKey);
        self::assertSame(8, $row->dim);
        self::assertSame(64, strlen($row->textHash));
        self::assertSame($this->clock->now(), $row->embeddedAt);
        self::assertCount(1, \WpStub::fired('post_embeddings_stored'));
    }

    /** T17 */
    public function testResultsMapByIndexAndAMissingIndexIsRetried(): void
    {
        $ids = $this->pending(3);
        $this->provider->script[] = function (array $texts): EmbeddingResult {
            return new EmbeddingResult([2 => $this->provider->vectorFor($texts[2]), 0 => $this->provider->vectorFor($texts[0])]);
        };
        $this->plugin->drain->run();
        $first = $this->rowOf($ids[0]);
        self::assertSame(IndexRow::OK, $first?->status);
        self::assertSame(
            VectorMath::pack(VectorMath::normalise($this->provider->vectorFor($this->provider->calls[0][0]))),
            $first->vector
        );
        self::assertSame(IndexRow::OK, $this->rowOf($ids[2])?->status);
        $missing = $this->rowOf($ids[1]);
        self::assertSame('missing_in_response', $missing?->errorCode);
        self::assertSame(IndexRow::PENDING, $missing->status);
        self::assertSame(1, $missing->attempts);
        self::assertSame($this->clock->now() + 300, $missing->nextAttemptAt);
    }

    /** T18 */
    public function testADrainThatStoresQueuesExactlyOneRebuild(): void
    {
        $this->settings(['batch_size' => 1, 'drain_max_batches' => 3]);
        $this->pending(3);
        $this->plugin->drain->run();
        self::assertSame(1, $this->rebuildEvents());
        $this->pending(1);
        $this->plugin->drain->run();
        self::assertSame(1, $this->rebuildEvents(), 'debounced while one is queued');
    }

    public function testCorruptStoredRowIsMarkedAtNextDrain(): void
    {
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $row = $this->rowOf($p->ID);
        self::assertNotNull($row);
        $row->vector = 'short';
        $this->store->save($row);
        $this->buildSidecar();
        self::assertSame([$p->ID], $this->plugin->state->get('bad_stored'));
        $this->plugin->drain->run();
        self::assertSame('bad_stored_vector', $this->rowOf($p->ID)?->errorCode);
        self::assertSame(IndexRow::ERROR, $this->rowOf($p->ID)?->status);
    }
}
