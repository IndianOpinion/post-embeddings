<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/** A spend meter decision. Unmetered means no meter is present. */
final class Reservation
{
    private function __construct(
        public readonly bool $allowed,
        public readonly bool $metered,
        public readonly ?string $id,
        public readonly string $reason
    ) {
    }

    public static function unmetered(): self
    {
        return new self(true, false, null, '');
    }

    public static function allowed(?string $id): self
    {
        return new self(true, true, $id, '');
    }

    public static function denied(string $reason): self
    {
        return new self(false, true, null, $reason);
    }
}
