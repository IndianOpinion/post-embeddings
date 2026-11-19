<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/**
 * A spend meter decision. Unmetered means no meter is present. `intent` is
 * the array the meter was asked with, reused when the call is recorded.
 */
final class Reservation
{
    /**
     * @param array<string, int|string> $intent
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $metered,
        public readonly ?string $id,
        public readonly string $reason,
        public readonly array $intent = []
    ) {
    }

    public static function unmetered(): self
    {
        return new self(true, false, null, '');
    }

    /** @param array<string, int|string> $intent */
    public static function allowed(?string $id, array $intent = []): self
    {
        return new self(true, true, $id, '', $intent);
    }

    /** @param array<string, int|string> $intent */
    public static function denied(string $reason, array $intent = []): self
    {
        return new self(false, true, null, $reason, $intent);
    }
}
