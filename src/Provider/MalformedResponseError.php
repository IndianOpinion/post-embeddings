<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * The response was not JSON or not the expected shape. Treated as transient;
 * `$excerpt` is a redacted excerpt of the body for the log.
 */
final class MalformedResponseError extends TransientProviderError
{
    public function __construct(string $message, public readonly string $excerpt = '', int $httpStatus = 200)
    {
        parent::__construct($message, null, true, $httpStatus);
    }
}
