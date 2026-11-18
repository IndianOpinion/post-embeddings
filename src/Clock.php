<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

/**
 * Wall clock and monotonic clock, injectable so tests control time.
 */
interface Clock
{
    /** Unix seconds, UTC. */
    public function now(): int;

    /** Monotonic seconds for measuring elapsed time. */
    public function monotonic(): float;
}
