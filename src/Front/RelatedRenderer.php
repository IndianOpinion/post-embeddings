<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Front;

use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Query\Related;
use IndianOpinion\PostEmbeddings\Query\Scored;
use WP_Post;

/**
 * Server render of the related-posts block and shortcode (cases 50 to 53,
 * 68). Any exception becomes the fallback and a log line.
 */
final class RelatedRenderer
{
    public const FALLBACKS = ['none', 'recent', 'same_term'];

    public function __construct(private readonly Related $related, private readonly Logger $logger)
    {
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{count: int, postTypes: list<string>, heading: string, showDate: bool, showExcerpt: bool, showImage: bool, fallback: string, minScore: ?float}
     */
    public static function attributes(array $raw): array
    {
        $count = isset($raw['count']) && is_numeric($raw['count']) ? (int) $raw['count'] : 4;
        $types = $raw['postTypes'] ?? [];
        if (is_string($types)) {
            $types = $types === '' ? [] : explode(',', $types);
        }
        $public = get_post_types(['public' => true]);
        $types = array_values(array_filter(
            array_map(static fn ($t): string => trim((string) $t), is_array($types) ? $types : []),
            static fn (string $t): bool => in_array($t, $public, true)
        ));
        $fallback = isset($raw['fallback']) && in_array($raw['fallback'], self::FALLBACKS, true) ? (string) $raw['fallback'] : 'none';
        $min = $raw['minScore'] ?? null;

        return [
            'count' => max(1, min(12, $count)),
            'postTypes' => $types,
            'heading' => isset($raw['heading']) && is_string($raw['heading']) ? $raw['heading'] : 'Related',
            'showDate' => self::flag($raw, 'showDate', true),
            'showExcerpt' => self::flag($raw, 'showExcerpt', false),
            'showImage' => self::flag($raw, 'showImage', true),
            'fallback' => $fallback,
            'minScore' => is_numeric($min) ? max(-1.0, min(1.0, (float) $min)) : null,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    public function render(array $raw, ?int $postId = null): string
    {
        $postId ??= self::currentPostId();
        if ($postId === null) {
            return '';
        }
        $attrs = self::attributes($raw);
        $args = ['count' => $attrs['count']];
        if ($attrs['postTypes'] !== []) {
            $args['post_types'] = $attrs['postTypes'];
        }
        if ($attrs['minScore'] !== null) {
            $args['min_score'] = $attrs['minScore'];
        }
        try {
            $posts = array_map(static fn (Scored $s): WP_Post => $s->post, $this->related->find($postId, $args));
        } catch (\Throwable $e) {
            $this->logger->error('related_error', ['post' => $postId, 'class' => get_class($e)]);
            $posts = [];
        }
        if ($posts === []) {
            $posts = $this->fallback($postId, $attrs);
        }
        if ($posts === []) {
            $html = '';
        } else {
            $html = '<section class="wp-block-post-embeddings-related post-embeddings-related">';
            if ($attrs['heading'] !== '') {
                $html .= '<h2 class="post-embeddings-related__heading">' . esc_html($attrs['heading']) . '</h2>';
            }
            $html .= Markup::list('related', $posts, $attrs) . '</section>';
        }
        $filtered = apply_filters('post_embeddings_related_html', $html, $posts, $attrs);

        return is_string($filtered) ? $filtered : $html;
    }

    /**
     * Latest posts of the same type, or sharing the first category. Never cached.
     *
     * @param array{count: int, postTypes: list<string>, fallback: string} $attrs
     * @return list<WP_Post>
     */
    public function fallback(int $postId, array $attrs): array
    {
        if ($attrs['fallback'] === 'none') {
            return [];
        }
        $type = get_post_type($postId);
        $query = [
            'post_type' => $attrs['postTypes'] !== [] ? $attrs['postTypes'] : [is_string($type) ? $type : 'post'],
            'post_status' => 'publish',
            'has_password' => false,
            'post__not_in' => [$postId],
            'posts_per_page' => $attrs['count'],
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        if ($attrs['fallback'] === 'same_term') {
            $cats = get_the_category($postId);
            if ($cats === []) {
                return [];
            }
            $query['category__in'] = [(int) $cats[0]->term_id];
        }
        try {
            $q = new \WP_Query($query);
        } catch (\Throwable $e) {
            $this->logger->error('related_fallback_error', ['class' => get_class($e)]);

            return [];
        }

        return array_values(array_filter($q->posts, static fn ($p): bool => $p instanceof WP_Post));
    }

    public static function currentPostId(): ?int
    {
        if (!is_singular()) {
            return null;
        }
        $id = (int) get_queried_object_id();

        return $id > 0 ? $id : null;
    }

    /** @param array<string, mixed> $raw */
    private static function flag(array $raw, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $raw)) {
            return $default;
        }
        $v = $raw[$key];

        return is_bool($v) ? $v : filter_var($v, FILTER_VALIDATE_BOOLEAN);
    }
}
