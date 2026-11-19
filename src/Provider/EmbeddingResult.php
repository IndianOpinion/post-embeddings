<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * What a provider returned, by input index: a vector, or an error code.
 */
final class EmbeddingResult
{
    /**
     * @param array<int, mixed> $vectors input index => vector as returned (validated later)
     * @param array<int, string> $errors input index => per-input error code
     */
    public function __construct(
        private readonly array $vectors,
        private readonly array $errors = [],
        public readonly ?int $inputTokens = null,
        public readonly int $httpStatus = 200
    ) {
    }

    public function hasVector(int $index): bool
    {
        return array_key_exists($index, $this->vectors);
    }

    public function vector(int $index): mixed
    {
        return $this->vectors[$index] ?? null;
    }

    public function error(int $index): ?string
    {
        return $this->errors[$index] ?? null;
    }
}
