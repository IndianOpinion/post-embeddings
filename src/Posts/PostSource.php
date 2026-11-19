<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Posts;

/**
 * Walks published posts by id with a keyset cursor, for backfill and progress.
 */
interface PostSource
{
    /**
     * Ids of published, unprotected posts of the given types, after the
     * cursor: ids above it in `asc` order, below it in `desc` order (0 means
     * start). At most $limit ids.
     *
     * @param list<string> $types
     * @return list<int>
     */
    public function ids(array $types, int $cursor, int $limit, string $order = 'asc', ?string $sinceGmt = null): array;

    /**
     * Published, unprotected posts of the given types.
     *
     * @param list<string> $types
     */
    public function count(array $types): int;
}
