<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * An embeddings provider. Implementations throw only ProviderError
 * exceptions for provider problems.
 */
interface EmbeddingProvider
{
    /** Adapter id, `^[a-z0-9_-]{1,32}$`; part of the model key. */
    public function id(): string;

    /** Model name as sent to the provider, up to 128 characters. */
    public function model(): string;

    /** Length of every vector returned, 1 to 65,535. */
    public function dimensions(): int;

    /** Character limit per input, at least 100. */
    public function maxInputChars(): int;

    /** Inputs per call, 1 to 256. */
    public function maxBatch(): int;

    /**
     * @param list<string> $texts non-empty strings, at most maxBatch()
     * @throws ProviderError
     */
    public function embed(array $texts): EmbeddingResult;
}
