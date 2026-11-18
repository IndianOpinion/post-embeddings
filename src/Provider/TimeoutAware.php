<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * Optional: an adapter that can use a shorter timeout for search queries.
 */
interface TimeoutAware
{
    public function withTimeout(int $seconds): EmbeddingProvider;
}
