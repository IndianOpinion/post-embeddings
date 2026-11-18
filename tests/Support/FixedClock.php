<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Support;

use IndianOpinion\PostEmbeddings\Clock;

final class FixedClock implements Clock
{
    public float $mono = 1000.0;

    /** Seconds added to the monotonic clock on every read. */
    public float $tick = 0.0;

    public function __construct(public int $now = 1767225600)
    {
    }

    public function now(): int
    {
        return $this->now;
    }

    public function monotonic(): float
    {
        $v = $this->mono;
        $this->mono += $this->tick;

        return $v;
    }

    public function advance(int $seconds): void
    {
        $this->now += $seconds;
        $this->mono += $seconds;
    }
}
