<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Unit;

use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PostEmbeddings\Query\Related;
use IndianOpinion\PostEmbeddings\Sidecar\VectorScan;
use IndianOpinion\PostEmbeddings\Query\Scored;
use IndianOpinion\PostEmbeddings\Tests\Support\TestCase;
use IndianOpinion\PostEmbeddings\Vector\DimensionError;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/** Cases 41 to 49 and 73. */
final class RelatedTest extends TestCase
{
    /** @return list<int> */
    private static function ids(array $scored): array
    {
        return array_map(static fn (Scored $s): int => $s->post->ID, $scored);
    }

    /** T40 */
    public function testPostWithoutCurrentModelRowGetsEmptyListAndBlockRendersFallback(): void
    {
        $p = $this->post();
        $other = $this->post(['post_date_gmt' => '2026-02-01 00:00:00']);
        $this->okRow($other->ID, self::axis(8, 0));
        $this->okRow($p->ID, self::axis(8, 0), 'old:model:8');
        self::assertSame([], $this->plugin->related->find($p->ID));
        $html = $this->plugin->relatedRenderer->render(['fallback' => 'recent'], $p->ID);
        self::assertStringContainsString('post-' . $other->ID . '/', $html);
        self::assertSame('', $this->plugin->relatedRenderer->render(['fallback' => 'none'], $p->ID));
    }

    /** T41 */
    public function testScanAsksForCountTimesOverfetchCappedAt200WithConfiguredScoreAndBudget(): void
    {
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->settings(['overfetch' => 3, 'min_score' => 0.42]);
        $spy = new SpyScan();
        $related = new Related(
            $this->store,
            $this->plugin->providers,
            $spy,
            $this->plugin->ranker,
            $this->plugin->cache,
            $this->plugin->settings,
            $this->plugin->state,
            $this->plugin->logger
        );
        $related->find($p->ID, ['count' => 4]);
        self::assertSame(12, $spy->calls[0]['k']);
        self::assertSame(0.42, $spy->calls[0]['options']->minScore);
        self::assertSame(0.3, $spy->calls[0]['options']->timeBudgetSeconds);
        self::assertSame($this->key(), $spy->calls[0]['key']);

        $this->settings(['overfetch' => 20]);
        $related->find($p->ID, ['count' => 12, 'min_score' => 0.1]);
        self::assertSame(200, $spy->calls[1]['k']);
        self::assertSame(0.1, $spy->calls[1]['options']->minScore);
    }

    /** T44 */
    public function testSelfTrashedAndDisallowedTypesAreFilteredOut(): void
    {
        $p = $this->post();
        $trashed = $this->post(['post_status' => 'trash']);
        $page = $this->post(['post_type' => 'page']);
        $good = $this->post();
        \WpStub::$extraTypes = [];
        $this->settings(['post_types' => ['post', 'page']]);
        foreach ([$p, $trashed, $page, $good] as $i => $post) {
            $this->okRow($post->ID, self::axis(8, 0, 0.05 * $i));
        }
        $hits = [new Hit($p->ID, $p->ID, 1.0), new Hit($trashed->ID, $trashed->ID, 0.99), new Hit($page->ID, $page->ID, 0.9), new Hit($good->ID, $good->ID, 0.8)];
        $ranked = $this->plugin->ranker->rank($hits, 5, ['post'], $p->ID);
        self::assertSame([$good->ID], self::ids($ranked));
        $ranked = $this->plugin->ranker->rank($hits, 5, ['post', 'page'], $p->ID);
        self::assertSame([$page->ID, $good->ID], self::ids($ranked));
    }

    /** T45 */
    public function testEqualScoresOrderByNewerDateThenLowerId(): void
    {
        $old = $this->post(['post_date_gmt' => '2025-01-01 00:00:00']);
        $newA = $this->post(['post_date_gmt' => '2026-03-01 00:00:00']);
        $newB = $this->post(['post_date_gmt' => '2026-03-01 00:00:00']);
        $best = $this->post(['post_date_gmt' => '2020-01-01 00:00:00']);
        $hits = [
            new Hit($old->ID, $old->ID, 0.5),
            new Hit($newB->ID, $newB->ID, 0.5),
            new Hit($newA->ID, $newA->ID, 0.5),
            new Hit($best->ID, $best->ID, 0.9),
        ];
        self::assertSame([$best->ID, $newA->ID, $newB->ID, $old->ID], self::ids($this->plugin->ranker->rank($hits, 10, ['post'])));
    }

    /** T46 */
    public function testNearIdenticalCopiesLeaveOneEntry(): void
    {
        $p = $this->post();
        $copyOfP = $this->post();
        $twinA = $this->post();
        $twinB = $this->post();
        $other = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->okRow($copyOfP->ID, self::axis(8, 0, 0.14));
        $this->okRow($twinA->ID, self::axis(8, 0, 1.0, 2));
        $this->okRow($twinB->ID, self::axis(8, 0, 1.01, 2));
        $this->okRow($other->ID, self::axis(8, 0, 1.0, 3));
        $this->buildSidecar();
        $this->settings(['min_score' => 0.1]);
        $ids = self::ids($this->plugin->related->find($p->ID, ['count' => 10]));
        self::assertNotContains($copyOfP->ID, $ids, 'a 0.99 copy of P is dropped');
        self::assertCount(1, array_intersect([$twinA->ID, $twinB->ID], $ids), 'one of a near-identical pair');
        self::assertContains($other->ID, $ids);

        $this->settings(['duplicate_score' => 1.01]);
        $ids = self::ids($this->plugin->related->find($p->ID, ['count' => 10]));
        self::assertContains($copyOfP->ID, $ids, '1.01 turns the rule off');
        self::assertCount(2, array_intersect([$twinA->ID, $twinB->ID], $ids));
    }

    /** T47 */
    public function testMaxAgeDropsOlderHits(): void
    {
        $p = $this->post(['post_date_gmt' => '2026-06-01 00:00:00']);
        $recent = $this->post(['post_date_gmt' => '2026-05-20 00:00:00']);
        $old = $this->post(['post_date_gmt' => '2025-01-01 00:00:00']);
        $this->okRow($p->ID, self::axis(8, 0));
        $this->okRow($recent->ID, self::axis(8, 0, 0.5, 2));
        $this->okRow($old->ID, self::axis(8, 0, 0.5, 3));
        $this->buildSidecar();
        self::assertCount(2, $this->plugin->related->find($p->ID, ['min_score' => 0]));
        $this->settings(['max_age_days' => 30]);
        self::assertSame([$recent->ID], self::ids($this->plugin->related->find($p->ID, ['min_score' => 0])));
    }

    /** T48 */
    public function testSecondCallHitsTheCacheAndANewBuildMissesIt(): void
    {
        $p = $this->post();
        $q = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $this->okRow($q->ID, self::axis(8, 0, 0.5));
        $this->buildSidecar();
        $first = self::ids($this->plugin->related->find($p->ID));
        $this->store->log = [];
        self::assertSame($first, self::ids($this->plugin->related->find($p->ID)));
        self::assertNotContains('page:0:400', $this->store->log);

        unlink($this->sidecarFile());
        self::assertSame($first, self::ids($this->plugin->related->find($p->ID)), 'cached: the missing file is not touched');
        $this->buildSidecar();
        $r = $this->store->get($q->ID, $this->key());
        self::assertNotNull($r);
        $this->store->pruneModel($this->key());
        $this->okRow($p->ID, self::axis(8, 0));
        $this->buildSidecar();
        self::assertSame([], $this->plugin->related->find($p->ID), 'the new generation misses the old entry');

        \WpStub::$extCache = true;
        $this->okRow($q->ID, self::axis(8, 0, 0.5));
        $this->buildSidecar();
        self::assertSame([$q->ID], self::ids($this->plugin->related->find($p->ID)));
        self::assertNotSame([], \WpStub::$cache, 'persistent object cache used when present');
    }

    /** T43 */
    public function testScanOverBudgetReturnsEmptyLogsOnceAndCachesNothing(): void
    {
        $p = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        $scan = new SpyScan();
        $scan->throw = new BudgetExceededException(10, 0.4, 0.3);
        $related = new Related(
            $this->store,
            $this->plugin->providers,
            $scan,
            $this->plugin->ranker,
            $this->plugin->cache,
            $this->plugin->settings,
            $this->plugin->state,
            $this->plugin->logger
        );
        self::assertSame([], $related->find($p->ID));
        self::assertSame([], $related->find($p->ID));
        self::assertCount(2, $scan->calls, 'nothing cached: the second call scans again');
        self::assertCount(1, array_filter($this->logLines, static fn (string $l): bool => str_contains($l, 'scan_budget')));
        self::assertSame([], array_filter(array_keys(\WpStub::$transients), static fn (string $k): bool => str_starts_with($k, 'post_embeddings_rel_')));
    }

    /** T71 */
    public function testComparingVectorsOfDifferentLengthsThrows(): void
    {
        $this->expectException(DimensionError::class);
        VectorMath::cosine([1.0, 0.0, 0.0], [1.0, 0.0]);
    }

    public function testNoCodePathComparesAPrefix(): void
    {
        $p = $this->post();
        $q = $this->post();
        $this->okRow($p->ID, self::axis(8, 0));
        // A row stored under the current key but with a 4-dimension vector.
        $this->okRow($q->ID, [1.0, 0.0, 0.0, 0.0]);
        $this->buildSidecar();
        self::assertSame([], $this->plugin->related->find($p->ID, ['min_score' => -1]));
        add_filter('post_embeddings_use_sidecar', static fn (): bool => false);
        $this->plugin->state->bumpGeneration();
        self::assertSame([], $this->plugin->related->find($p->ID, ['min_score' => -1]));
        self::assertNull(VectorMath::unpack(VectorMath::pack([1.0, 0.0, 0.0, 0.0]), 8));
    }
}

/** Records scan requests and returns nothing. */
final class SpyScan implements VectorScan
{
    /** @var list<array{key: string, k: int, options: ScanOptions}> */
    public array $calls = [];

    public ?\Throwable $throw = null;

    public function top(string $modelKey, int $dim, array $query, int $k, ScanOptions $options): array
    {
        $this->calls[] = ['key' => $modelKey, 'k' => $k, 'options' => $options];
        if ($this->throw !== null) {
            throw $this->throw;
        }

        return [];
    }
}
