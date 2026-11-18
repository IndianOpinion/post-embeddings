<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

use IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider;

/**
 * The string `<adapter id>:<model>:<dimension>`. Two vectors are comparable
 * only when their model keys are equal.
 */
final class ModelKey
{
    private function __construct()
    {
    }

    public static function of(EmbeddingProvider $provider): string
    {
        return $provider->id() . ':' . $provider->model() . ':' . $provider->dimensions();
    }

    /** Short, file-name-safe hash of a model key. */
    public static function shortHash(string $modelKey): string
    {
        return substr(hash('sha256', $modelKey), 0, 12);
    }
}
