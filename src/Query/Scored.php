<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Query;

use WP_Post;

/** A post with its similarity score. Scores are never shown to readers. */
final class Scored
{
    public function __construct(public readonly WP_Post $post, public readonly float $score)
    {
    }
}
