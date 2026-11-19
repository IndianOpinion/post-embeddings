<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/** HTTP 400 or 413 that the adapter classifies as input length. */
final class InputTooLongError extends \RuntimeException implements ProviderError
{
}
