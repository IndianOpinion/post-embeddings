<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Posts;

use IndianOpinion\PostEmbeddings\Settings;
use WP_Post;

/**
 * An eligible post: published, of a configured type, no password, and not
 * excluded by `post_embeddings_is_eligible`.
 */
final class Eligibility
{
    public function __construct(private readonly Settings $settings)
    {
    }

    public function isEligible(WP_Post $post): bool
    {
        $ok = $post->post_status === 'publish'
            && $post->post_password === ''
            && in_array($post->post_type, $this->settings->postTypes(), true);

        return (bool) apply_filters('post_embeddings_is_eligible', $ok, $post);
    }

    /**
     * The eligible posts among $ids, by id, with one query.
     *
     * @param list<int> $ids
     * @param list<string>|null $types null means the configured types
     * @return array<int, WP_Post>
     */
    public function filterIds(array $ids, ?array $types = null): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        $types ??= $this->settings->postTypes();
        $query = new \WP_Query([
            'post__in' => $ids,
            'post_type' => $types === [] ? ['post'] : $types,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => count($ids),
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'orderby' => 'post__in',
            'suppress_filters' => true,
        ]);
        $out = [];
        foreach ($query->posts as $post) {
            if ($post instanceof WP_Post && $this->isEligible($post)) {
                $out[$post->ID] = $post;
            }
        }

        return $out;
    }
}
