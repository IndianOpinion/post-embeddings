<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Vector;

/**
 * Two vectors of different lengths were about to be compared. They come
 * from different models, and comparing the overlap would give a believable,
 * wrong number.
 */
final class DimensionError extends \LengthException
{
    public function __construct(public readonly int $expected, public readonly int $given)
    {
        parent::__construct(sprintf('Vector length %d does not match %d; refusing to compare.', $given, $expected));
    }
}
