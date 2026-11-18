<?php

/**
 * Runs as is from the repository root, without WordPress:
 *     php examples/04-standalone-vectors.php
 *
 * Shows the text pipeline and the vector rules the plugin relies on: NFC
 * normalisation, truncation at a whitespace boundary, the per-script token
 * estimate, unit-length float32 packing, and the refusal to compare vectors
 * of different lengths.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

if (!function_exists('apply_filters')) {
    function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
    {
        return $value;
    }
}

use IndianOpinion\PostEmbeddings\Provider\FakeProvider;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;
use IndianOpinion\PostEmbeddings\Vector\DimensionError;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

$text = EmbeddingText::cleanPlain("<p>Petrol and diesel cost more from Monday.</p>  भारत में ईंधन की कीमतें");
echo 'Clean text: ', $text, "\n";
echo 'Cut to 30 characters: ', EmbeddingText::truncate($text, 30), "\n";
echo 'Estimated tokens: ', (new TokenEstimator())->estimate($text), "\n";

$a = FakeProvider::vectorFor($text, 8);
$blob = VectorMath::pack(VectorMath::normalise($a));
echo 'Packed bytes: ', strlen($blob), "\n";
echo 'Self similarity: ', round(VectorMath::cosine(VectorMath::unpack($blob, 8) ?? [], $a), 6), "\n";

try {
    VectorMath::cosine($a, array_slice($a, 0, 4));
} catch (DimensionError $e) {
    echo 'Refused: ', $e->getMessage(), "\n";
}
