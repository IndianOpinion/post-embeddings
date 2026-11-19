<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Vector;

/**
 * Validation, normalisation, packing and comparison of vectors.
 *
 * Stored vectors are unit length, float32 little-endian, exactly 4 * dim
 * bytes. Unpacking uses unpack() with its 1-based keys turned into a list.
 */
final class VectorMath
{
    private function __construct()
    {
    }

    /**
     * Why a provider vector must not be stored, or null when it is fine.
     * Reasons: not_list, not_number, non_finite, length, zero_norm.
     */
    public static function problem(mixed $vector, int $dim): ?string
    {
        if (!is_array($vector) || !array_is_list($vector)) {
            return 'not_list';
        }
        $sum = 0.0;
        foreach ($vector as $value) {
            if (!is_int($value) && !is_float($value)) {
                return 'not_number';
            }
            if (!is_finite((float) $value) || abs((float) $value) > 3.4028234663852886e38) {
                return 'non_finite';
            }
            $sum += (float) $value * (float) $value;
        }
        if (count($vector) !== $dim) {
            return 'length';
        }
        if ($sum <= 0.0 || !is_finite($sum)) {
            return 'zero_norm';
        }

        return null;
    }

    /**
     * Unit length in double precision.
     *
     * @param list<int|float> $vector
     * @return list<float>
     */
    public static function normalise(array $vector): array
    {
        $sum = 0.0;
        foreach ($vector as $v) {
            $sum += (float) $v * (float) $v;
        }
        $norm = sqrt($sum);
        if ($norm <= 0.0) {
            throw new \InvalidArgumentException('A zero vector has no direction.');
        }
        $out = [];
        foreach ($vector as $v) {
            $out[] = (float) $v / $norm;
        }

        return $out;
    }

    /**
     * @param list<int|float> $vector
     */
    public static function pack(array $vector): string
    {
        return pack('g*', ...$vector);
    }

    /**
     * The floats in a stored blob, or null when its length is not 4 * dim.
     *
     * @return list<float>|null
     */
    public static function unpack(?string $blob, int $dim): ?array
    {
        if ($blob === null || $dim < 1 || strlen($blob) !== 4 * $dim) {
            return null;
        }
        $values = unpack('g' . $dim, $blob);
        if ($values === false) {
            return null;
        }
        $out = [];
        for ($i = 1; $i <= $dim; $i++) {
            $out[] = (float) $values[$i];
        }

        return $out;
    }

    /**
     * Cosine similarity. Throws rather than compare vectors of different lengths.
     *
     * @param list<float> $a
     * @param list<float> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = count($a);
        if ($n !== count($b)) {
            throw new DimensionError($n, count($b));
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $na += $a[$i] * $a[$i];
            $nb += $b[$i] * $b[$i];
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            throw new \InvalidArgumentException('A zero vector has no direction.');
        }

        return max(-1.0, min(1.0, $dot / (sqrt($na) * sqrt($nb))));
    }
}
