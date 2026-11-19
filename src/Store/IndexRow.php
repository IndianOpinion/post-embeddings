<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Store;

/**
 * The stored record for one post under one model key. Times are Unix seconds.
 */
final class IndexRow
{
    public const PENDING = 'pending';
    public const OK = 'ok';
    public const EMPTY = 'empty';
    public const INELIGIBLE = 'ineligible';
    public const ERROR = 'error';

    public const STATUSES = [self::PENDING, self::OK, self::EMPTY, self::INELIGIBLE, self::ERROR];

    public function __construct(
        public int $postId,
        public string $modelKey,
        public string $status,
        public string $textHash = '',
        public int $dim = 0,
        public ?string $vector = null,
        public ?int $tokens = null,
        public int $attempts = 0,
        public ?string $errorCode = null,
        public ?int $nextAttemptAt = null,
        public ?int $embeddedAt = null,
        public int $updatedAt = 0
    ) {
        if (!in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown status ' . $status);
        }
    }
}
