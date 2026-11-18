<?php

/**
 * Public PHP functions. Both return lists of WP_Post and never throw.
 */

declare(strict_types=1);

use IndianOpinion\PostEmbeddings\Plugin;
use IndianOpinion\PostEmbeddings\Query\Scored;

if (!function_exists('post_embeddings_related')) {
    /**
     * Related posts for a post, best first. Empty when there is nothing
     * semantic to show.
     *
     * @param array<string, mixed> $args count (1 to 12), post_types, min_score
     * @return list<WP_Post>
     */
    function post_embeddings_related(int $post_id, array $args = []): array
    {
        $plugin = Plugin::instance();
        if ($plugin === null) {
            return [];
        }
        try {
            return array_map(static fn (Scored $s): WP_Post => $s->post, $plugin->related->find($post_id, $args));
        } catch (\Throwable $e) {
            $plugin->logger->error('related_error', ['post' => $post_id, 'class' => get_class($e)]);

            return [];
        }
    }
}

if (!function_exists('post_embeddings_search')) {
    /**
     * Semantic search, or keyword results when the query cannot be embedded.
     *
     * @param array<string, mixed> $args count (1 to 20)
     * @return list<WP_Post>
     */
    function post_embeddings_search(string $query, array $args = []): array
    {
        $plugin = Plugin::instance();
        if ($plugin === null) {
            return [];
        }
        try {
            $count = isset($args['count']) && is_numeric($args['count']) ? (int) $args['count'] : 10;
            $outcome = $plugin->search->search($query, $count, $_SERVER);

            return array_map(static fn (Scored $s): WP_Post => $s->post, $outcome->results);
        } catch (\Throwable $e) {
            $plugin->logger->error('search_error', ['class' => get_class($e)]);

            return [];
        }
    }
}
