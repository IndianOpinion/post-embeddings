<?php

/**
 * Theme usage without blocks: related posts under the content, and filters
 * that change defaults.
 */

declare(strict_types=1);

// Related posts after the content of single posts.
add_filter('the_content', static function (string $content): string {
    if (!is_singular('post') || !in_the_loop() || !function_exists('post_embeddings_related')) {
        return $content;
    }
    $items = '';
    foreach (post_embeddings_related(get_the_ID(), ['count' => 3]) as $post) {
        $items .= '<li><a href="' . esc_url((string) get_permalink($post)) . '">' . esc_html(get_the_title($post)) . '</a></li>';
    }

    return $items === '' ? $content : $content . '<h2>Read next</h2><ul>' . $items . '</ul>';
});

// Embed pages as well as posts.
add_filter('post_embeddings_post_types', static fn (array $types): array => array_values(array_unique([...$types, 'page'])));

// Never embed posts in a category with the slug "notices".
add_filter('post_embeddings_is_eligible', static fn (bool $ok, WP_Post $post): bool => $ok && !has_category('notices', $post), 10, 2);

// A stricter search rate limit: 5 fresh query embeddings per 10 minutes.
add_filter('post_embeddings_rate_limit', static fn (): array => ['limit' => 5, 'window' => 600]);
