<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * Timeout, connection error, HTTP 5xx or 429. Retried with backoff.
 *
 * `$maybeBilled` is true when the request may have reached the provider and
 * been charged (a timeout after sending); such a call counts against caps.
 */
class TransientProviderError extends \RuntimeException implements ProviderError
{
    public function __construct(
        string $message,
        public readonly ?int $retryAfter = null,
        public readonly bool $maybeBilled = false,
        public readonly int $httpStatus = 0
    ) {
        parent::__construct($message);
    }
}
