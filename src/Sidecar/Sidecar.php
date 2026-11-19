<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Sidecar;

use IndianOpinion\PackedVectors\Builder;
use IndianOpinion\PackedVectors\BuildReport;
use IndianOpinion\PackedVectors\ChunkedScanner;
use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Exception\PackedVectorsError;
use IndianOpinion\PackedVectors\Exception\UnavailableException;
use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\Scanner;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\ModelKey;
use IndianOpinion\PostEmbeddings\Posts\Eligibility;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/**
 * The packed sidecar: debounced rebuilds, staleness checks, and scans that
 * fall back to the chunked scanner over the table when the file cannot
 * answer (cases 35 to 44).
 */
final class Sidecar implements VectorScan
{
    public const REBUILD_HOOK = 'post_embeddings_rebuild';
    public const HOURLY_HOOK = 'post_embeddings_hourly';
    public const PAGE = 500;
    public const CHUNK_PAGE = 400;
    public const CHUNK_BUDGET = 1.5;
    public const MAX_BAD_IDS = 100;

    public function __construct(
        private readonly Directory $directory,
        private readonly IndexStore $store,
        private readonly Eligibility $eligibility,
        private readonly State $state,
        private readonly Clock $clock,
        private readonly Logger $logger
    ) {
    }

    public function enabled(): bool
    {
        return (bool) apply_filters('post_embeddings_use_sidecar', true);
    }

    public function path(string $modelKey): string
    {
        return $this->directory->path() . '/' . self::fileName($modelKey);
    }

    public static function fileName(string $modelKey, ?int $blogId = null): string
    {
        $blogId ??= get_current_blog_id();

        return 'post-embeddings-' . $blogId . '-' . ModelKey::shortHash($modelKey) . '.vec';
    }

    /** Queues one rebuild 60 seconds ahead, unless one is already queued. */
    public function queueRebuild(): bool
    {
        if (wp_next_scheduled(self::REBUILD_HOOK) !== false) {
            return false;
        }

        return wp_schedule_single_event($this->clock->now() + 60, self::REBUILD_HOOK) !== false;
    }

    /**
     * Builds the file for the current model key now. Returns the report as
     * an array, or null when it could not run.
     *
     * @return array<string, mixed>|null
     */
    public function rebuild(string $modelKey, int $dim, ?int $maxAge = null): ?array
    {
        if (!$this->enabled()) {
            return null;
        }
        $problem = $this->directory->protect();
        if ($problem !== null) {
            $this->state->set('sidecar_error', $problem);
            $this->logger->error('sidecar_unavailable', ['reason' => $problem]);

            return null;
        }
        $builder = new Builder($this->path($modelKey), $dim);
        $bad = [];
        $factory = function () use ($modelKey, $dim, &$bad): \Generator {
            return $this->records($modelKey, $dim, $bad);
        };
        try {
            $report = $maxAge === null ? $builder->build($factory()) : $builder->rebuildIfStale($maxAge, $factory);
        } catch (PackedVectorsError $e) {
            $this->state->set('sidecar_error', 'build_failed');
            $this->logger->error('sidecar_unavailable', ['reason' => 'build_failed', 'class' => get_class($e)]);

            return null;
        }
        if ($report === null) {
            return null;
        }
        if ($report->skippedForLock()) {
            if (!$this->state->get('rebuild_requeued', false)) {
                $this->state->set('rebuild_requeued', true);
                $this->queueRebuild();
            }

            return self::reportArray($report, $modelKey, true);
        }
        $this->state->delete('rebuild_requeued');
        $this->state->delete('sidecar_error');
        $this->rememberBad($bad);
        $this->removeOtherModels($modelKey);
        $this->state->bumpGeneration();
        $array = self::reportArray($report, $modelKey, false);
        $this->state->set('last_build', $array);
        $context = [
            'written' => $report->written(),
            'skipped' => $report->skippedTotal(),
            'bytes' => $report->bytes(),
            'seconds' => round($report->seconds(), 3),
        ];
        foreach ($report->skipped() as $reason => $n) {
            if ($n > 0) {
                $context['skipped_' . $reason] = $n;
            }
        }
        if ($report->skippedTotal() > 0) {
            $this->logger->warning('sidecar_skipped', $context);
        } else {
            $this->logger->info('sidecar_built', $context);
        }
        do_action('post_embeddings_sidecar_built', $array);

        return $array;
    }

    /**
     * Rows of the current key with status ok whose posts are still eligible,
     * by ascending post id, in keyset pages of 500.
     *
     * @param list<int> $bad ids of rows whose blob does not decode
     * @return \Generator<int, array{0: int, 1: int, 2: list<float>}>
     */
    private function records(string $modelKey, int $dim, array &$bad): \Generator
    {
        $after = 0;
        do {
            $rows = $this->store->page($modelKey, $after, self::PAGE, IndexRow::OK);
            if ($rows === []) {
                break;
            }
            $after = end($rows)->postId;
            $eligible = $this->eligibility->filterIds(array_map(static fn (IndexRow $r): int => $r->postId, $rows));
            foreach ($rows as $row) {
                if (!isset($eligible[$row->postId])) {
                    continue;
                }
                $vector = VectorMath::unpack($row->vector, $dim);
                if ($vector === null) {
                    if (count($bad) < self::MAX_BAD_IDS) {
                        $bad[] = $row->postId;
                    }
                    // Hand the builder an empty vector so it counts the row as skipped.
                    yield [$row->postId, $row->postId, []];
                    continue;
                }
                yield [$row->postId, $row->postId, $vector];
            }
            $count = count($rows);
            unset($rows, $eligible);
        } while ($count === self::PAGE);
    }

    /**
     * Top hits for a query vector. Uses the sidecar, or the chunked scanner
     * over the table when the file cannot answer (and queues one rebuild).
     *
     * @param list<float> $query
     * @return list<Hit>
     * @throws BudgetExceededException when the scan runs past its budget
     * @throws PackedVectorsError when both paths fail
     */
    public function top(string $modelKey, int $dim, array $query, int $k, ScanOptions $options): array
    {
        if ($this->enabled()) {
            try {
                return (new Scanner($this->path($modelKey), $options))->top($query, $k)->hits();
            } catch (UnavailableException $e) {
                $this->logger->warning('sidecar_unavailable', ['reason' => (new \ReflectionClass($e))->getShortName()]);
                $this->queueRebuild();
            }
        }

        return $this->chunked($modelKey, $dim, $query, $k, $options->withTimeBudgetSeconds(self::CHUNK_BUDGET));
    }

    /**
     * @param list<float> $query
     * @return list<Hit>
     */
    public function chunked(string $modelKey, int $dim, array $query, int $k, ScanOptions $options): array
    {
        $bad = [];
        $fetch = function (int $afterId, int $limit) use ($modelKey, $dim, &$bad): array {
            $page = [];
            foreach ($this->store->page($modelKey, $afterId, $limit, IndexRow::OK) as $row) {
                $vector = VectorMath::unpack($row->vector, $dim);
                if ($vector === null && count($bad) < 10) {
                    $bad[] = $row->postId;
                }
                $page[] = [$row->postId, $row->postId, $vector ?? []];
            }

            return $page;
        };
        $hits = (new ChunkedScanner($fetch, $dim, $options, self::CHUNK_PAGE))->top($query, $k)->hits();
        $this->rememberBad($bad);

        return $hits;
    }

    /**
     * Header facts about the current file, or null when it is unavailable.
     *
     * @return array<string, int|string>|null
     */
    public function info(string $modelKey): ?array
    {
        try {
            $info = (new Scanner($this->path($modelKey)))->info();
        } catch (PackedVectorsError) {
            return null;
        }

        return [
            'path' => $info->path,
            'dim' => $info->dim,
            'count' => $info->count,
            'bytes' => $info->fileSize,
            'built_at' => $info->builtAt,
            'age' => max(0, $this->clock->now() - $info->builtAt),
        ];
    }

    /**
     * Removes this site's files for other model keys, with their lock and
     * temporary files.
     */
    public function removeOtherModels(string $modelKey): int
    {
        $keep = self::fileName($modelKey);

        return $this->removeFiles(static fn (string $base): bool => !str_starts_with($base, $keep));
    }

    /** Removes every file this site's sidecars created. */
    public function removeAll(): int
    {
        return $this->removeFiles(static fn (string $base): bool => true);
    }

    /**
     * @param callable(string): bool $which
     */
    private function removeFiles(callable $which): int
    {
        $dir = $this->directory->path();
        $prefix = 'post-embeddings-' . get_current_blog_id() . '-';
        $files = glob($dir . '/' . $prefix . '*');
        $n = 0;
        foreach ($files === false ? [] : $files as $file) {
            $base = basename($file);
            if (preg_match('/^post-embeddings-\d+-[0-9a-f]{12}\.vec(\.lock|\.[0-9a-f]{16}\.tmp)?$/', $base) !== 1) {
                continue;
            }
            if ($which($base) && is_file($file) && unlink($file)) {
                $n++;
            }
        }

        return $n;
    }

    /** @param list<int> $ids */
    private function rememberBad(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $known = $this->state->get('bad_stored', []);
        $known = is_array($known) ? array_map('intval', $known) : [];
        $merged = array_slice(array_values(array_unique(array_merge($known, $ids))), 0, self::MAX_BAD_IDS);
        if ($merged !== $known) {
            $this->state->set('bad_stored', $merged);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function reportArray(BuildReport $r, string $modelKey, bool $lockBusy): array
    {
        return [
            'model_key' => $modelKey,
            'written' => $r->written(),
            'skipped' => $r->skipped(),
            'skipped_total' => $r->skippedTotal(),
            'bytes' => $r->bytes(),
            'seconds' => round($r->seconds(), 3),
            'built_at' => $r->builtAt(),
            'lock_busy' => $lockBusy,
        ];
    }
}
