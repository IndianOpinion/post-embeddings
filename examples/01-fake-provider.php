<?php

/**
 * Use the deterministic fake provider on a development site, with no
 * account and no network. Results are NOT semantic: two texts that mean the
 * same thing get unrelated vectors. Good for checking the plumbing only.
 *
 * Put this in a must-use plugin on a development site, then:
 *     wp post-embeddings backfill --cap=200 --yes
 *     wp post-embeddings rebuild
 *     wp post-embeddings related <post_id>
 */

declare(strict_types=1);

use IndianOpinion\PostEmbeddings\Provider\FakeProvider;

add_filter('post_embeddings_provider', static fn () => new FakeProvider(64));
