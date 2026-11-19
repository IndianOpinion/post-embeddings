<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

use IndianOpinion\PostEmbeddings\Store\IndexRow;

/** One post on its way to the provider. */
final class Item
{
    public function __construct(
        public readonly IndexRow $row,
        public readonly string $text
    ) {
    }
}
