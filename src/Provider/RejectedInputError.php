<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/** Any other HTTP 4xx. */
final class RejectedInputError extends \RuntimeException implements ProviderError
{
    public function __construct(string $message, public readonly int $httpStatus)
    {
        parent::__construct($message);
    }
}
