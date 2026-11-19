<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Store;

/**
 * The index table. WpdbIndexStore is the real one; MemoryIndexStore backs
 * tests and examples.
 */
interface IndexStore
{
    /** True when the table exists. */
    public function ready(): bool;

    public function get(int $postId, string $modelKey): ?IndexRow;

    /**
     * Rows for many posts under one key, by post id.
     *
     * @param list<int> $postIds
     * @return array<int, IndexRow>
     */
    public function getMany(array $postIds, string $modelKey): array;

    /** Insert or replace one row. */
    public function save(IndexRow $row): void;

    /** Remove every row of a post, under every key. Returns rows removed. */
    public function deletePost(int $postId): int;

    /**
     * Pending rows due at $now, oldest updated_at first.
     *
     * @return list<IndexRow>
     */
    public function duePending(string $modelKey, int $now, int $limit): array;

    /**
     * Keyset page by ascending post id, optionally only one status.
     *
     * @return list<IndexRow>
     */
    public function page(string $modelKey, int $afterPostId, int $limit, ?string $status = null): array;

    /**
     * Counts per model key and status.
     *
     * @return array<string, array<string, int>>
     */
    public function counts(): array;

    /** error rows back to pending, attempts reset. Returns rows changed. */
    public function retryErrors(string $modelKey): int;

    /** Remove every row of one model key. */
    public function pruneModel(string $modelKey): int;
}
