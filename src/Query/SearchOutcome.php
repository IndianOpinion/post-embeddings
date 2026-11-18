<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Query;

/**
 * Result of a search: `semantic`, `keyword` (fallback), or `invalid` (query
 * too short or too long, nothing run).
 */
final class SearchOutcome
{
    /**
     * @param list<Scored> $results scores are 0 in keyword mode
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $results,
        public readonly string $query,
        public readonly ?string $reason = null
    ) {
    }
}
