<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

/**
 * Deterministic unit vectors derived from a hash of the text. For tests and
 * local development only: the results are NOT semantic. Two texts that mean
 * the same thing get unrelated vectors. Enabled only through the
 * `post_embeddings_provider` filter, never offered in the settings screen.
 */
final class FakeProvider implements EmbeddingProvider
{
    /** @var list<list<string>> every batch sent, for tests */
    public array $calls = [];

    public function __construct(
        private readonly int $dimensions = 64,
        private readonly string $model = 'hash',
        private readonly int $maxBatch = 64,
        private readonly int $maxInputChars = 8000
    ) {
        if ($dimensions < 1 || $dimensions > 65535) {
            throw new \InvalidArgumentException('Dimensions must be 1 to 65,535.');
        }
    }

    public function id(): string
    {
        return 'fake';
    }

    public function model(): string
    {
        return $this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    public function maxInputChars(): int
    {
        return $this->maxInputChars;
    }

    public function maxBatch(): int
    {
        return $this->maxBatch;
    }

    public function embed(array $texts): EmbeddingResult
    {
        $this->calls[] = array_values($texts);
        $vectors = [];
        foreach (array_values($texts) as $i => $text) {
            $vectors[$i] = self::vectorFor($text, $this->dimensions);
        }

        return new EmbeddingResult($vectors, [], null, 200);
    }

    /**
     * @return list<float>
     */
    public static function vectorFor(string $text, int $dim): array
    {
        $out = [];
        $block = 0;
        while (count($out) < $dim) {
            $bytes = hash('sha256', $block . "\0" . $text, true);
            foreach (str_split($bytes, 2) as $pair) {
                $v = unpack('n', $pair);
                $out[] = (($v === false ? 0 : (int) $v[1]) / 32767.5) - 1.0;
                if (count($out) === $dim) {
                    break;
                }
            }
            $block++;
        }
        $norm = sqrt(array_sum(array_map(static fn (float $x): float => $x * $x, $out)));
        if ($norm <= 0.0) {
            $out[0] = 1.0;
            $norm = 1.0;
        }

        return array_map(static fn (float $x): float => $x / $norm, $out);
    }
}
