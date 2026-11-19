<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Store;

/**
 * In-memory index for tests and examples. Same semantics as the table.
 */
final class MemoryIndexStore implements IndexStore
{
    /** @var array<string, IndexRow> "postId|modelKey" => row */
    public array $rows = [];

    public bool $isReady = true;

    /** @var list<string> */
    public array $log = [];

    public function ready(): bool
    {
        return $this->isReady;
    }

    public function get(int $postId, string $modelKey): ?IndexRow
    {
        $this->log[] = 'get';
        $row = $this->rows[$postId . '|' . $modelKey] ?? null;

        return $row === null ? null : clone $row;
    }

    public function getMany(array $postIds, string $modelKey): array
    {
        $out = [];
        foreach ($postIds as $id) {
            $row = $this->rows[$id . '|' . $modelKey] ?? null;
            if ($row !== null) {
                $out[$id] = clone $row;
            }
        }

        return $out;
    }

    public function save(IndexRow $row): void
    {
        $this->log[] = 'save';
        $this->rows[$row->postId . '|' . $row->modelKey] = clone $row;
    }

    public function deletePost(int $postId): int
    {
        $n = 0;
        foreach ($this->rows as $k => $row) {
            if ($row->postId === $postId) {
                unset($this->rows[$k]);
                $n++;
            }
        }

        return $n;
    }

    public function duePending(string $modelKey, int $now, int $limit): array
    {
        $due = array_filter(
            $this->rows,
            static fn (IndexRow $r): bool => $r->modelKey === $modelKey && $r->status === IndexRow::PENDING
                && ($r->nextAttemptAt === null || $r->nextAttemptAt <= $now)
        );
        usort($due, static fn (IndexRow $a, IndexRow $b): int => [$a->updatedAt, $a->postId] <=> [$b->updatedAt, $b->postId]);

        return array_map(static fn (IndexRow $r): IndexRow => clone $r, array_slice($due, 0, $limit));
    }

    public function page(string $modelKey, int $afterPostId, int $limit, ?string $status = null): array
    {
        $this->log[] = 'page:' . $afterPostId . ':' . $limit;
        $rows = array_filter(
            $this->rows,
            static fn (IndexRow $r): bool => $r->modelKey === $modelKey && $r->postId > $afterPostId
                && ($status === null || $r->status === $status)
        );
        usort($rows, static fn (IndexRow $a, IndexRow $b): int => $a->postId <=> $b->postId);

        return array_map(static fn (IndexRow $r): IndexRow => clone $r, array_slice($rows, 0, $limit));
    }

    public function counts(): array
    {
        $out = [];
        foreach ($this->rows as $row) {
            $out[$row->modelKey][$row->status] = ($out[$row->modelKey][$row->status] ?? 0) + 1;
        }

        return $out;
    }

    public function retryErrors(string $modelKey): int
    {
        $n = 0;
        foreach ($this->rows as $row) {
            if ($row->modelKey === $modelKey && $row->status === IndexRow::ERROR) {
                $row->status = IndexRow::PENDING;
                $row->attempts = 0;
                $row->errorCode = null;
                $row->nextAttemptAt = null;
                $n++;
            }
        }

        return $n;
    }

    public function pruneModel(string $modelKey): int
    {
        $n = 0;
        foreach ($this->rows as $k => $row) {
            if ($row->modelKey === $modelKey) {
                unset($this->rows[$k]);
                $n++;
            }
        }

        return $n;
    }
}
