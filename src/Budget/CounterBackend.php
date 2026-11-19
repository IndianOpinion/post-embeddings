<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/**
 * Where the daily counters live. `add()` must be atomic per counter, so two
 * processes adding at once never lose an update.
 */
interface CounterBackend
{
    public const NAMES = ['index_posts', 'index_tokens', 'queries'];

    /**
     * Adds the deltas (negative to give back) to the counters of one UTC
     * date and returns every counter of that date after the change. No
     * counter goes below zero.
     *
     * @param array<string, int> $deltas keys from NAMES
     * @return array{index_posts: int, index_tokens: int, queries: int}
     */
    public function add(string $date, array $deltas): array;

    /**
     * @return array{index_posts: int, index_tokens: int, queries: int}
     */
    public function read(string $date): array;
}
