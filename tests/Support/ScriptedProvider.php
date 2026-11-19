<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Support;

use IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingResult;
use IndianOpinion\PostEmbeddings\Provider\FakeProvider;

/**
 * A provider whose answers are scripted per call. With no script left it
 * answers like the fake provider. A script entry is a Throwable to throw, an
 * EmbeddingResult, or a callable(list<string>): EmbeddingResult.
 */
class ScriptedProvider implements EmbeddingProvider
{
    /** @var list<list<string>> */
    public array $calls = [];

    /** @var list<mixed> */
    public array $script = [];

    /** @var array<string, list<float>> text prefix => vector, for controlled geometry */
    public array $vectors = [];

    /** @var callable|null runs inside every call, before answering */
    public $during = null;

    public function __construct(
        public int $dim = 8,
        public string $modelName = 'test-model',
        public int $batch = 64,
        public int $maxChars = 8000
    ) {
    }

    public function id(): string
    {
        return 'test';
    }

    public function model(): string
    {
        return $this->modelName;
    }

    public function dimensions(): int
    {
        return $this->dim;
    }

    public function maxInputChars(): int
    {
        return $this->maxChars;
    }

    public function maxBatch(): int
    {
        return $this->batch;
    }

    public function embed(array $texts): EmbeddingResult
    {
        $this->calls[] = $texts;
        if ($this->during !== null) {
            ($this->during)($texts);
        }
        $next = array_shift($this->script);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if ($next instanceof EmbeddingResult) {
            return $next;
        }
        if (is_callable($next)) {
            return $next($texts);
        }
        $out = [];
        foreach (array_values($texts) as $i => $t) {
            $out[$i] = $this->vectorFor($t);
        }

        return new EmbeddingResult($out);
    }

    /** @return list<float> */
    public function vectorFor(string $text): array
    {
        foreach ($this->vectors as $prefix => $v) {
            if (str_starts_with($text, (string) $prefix)) {
                return $v;
            }
        }

        return FakeProvider::vectorFor($text, $this->dim);
    }

    /** @return list<string> every text sent, flattened */
    public function sent(): array
    {
        return array_merge(...($this->calls ?: [[]]));
    }
}
