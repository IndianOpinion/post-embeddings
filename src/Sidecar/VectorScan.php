<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Sidecar;

use IndianOpinion\PackedVectors\Hit;
use IndianOpinion\PackedVectors\ScanOptions;

/**
 * Top-k scan over the current model's vectors.
 */
interface VectorScan
{
    /**
     * @param list<float> $query
     * @return list<Hit>
     */
    public function top(string $modelKey, int $dim, array $query, int $k, ScanOptions $options): array;
}
