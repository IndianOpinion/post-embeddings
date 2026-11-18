<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/** HTTP 401 or 403: the drain pauses until settings are saved or resumed. */
final class AuthProviderError extends \RuntimeException implements ProviderError
{
}
