<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Front;

use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Query\Scored;
use IndianOpinion\PostEmbeddings\Query\Search;
use WP_Post;

/**
 * Server render of the search block and shortcode (cases 54 to 58).
 */
final class SearchRenderer
{
    private static int $instance = 0;

    public function __construct(private readonly Search $search, private readonly Logger $logger)
    {
    }

    public static function param(): string
    {
        $name = apply_filters('post_embeddings_search_param', 'semantic');

        return is_string($name) && preg_match('/^[a-z][a-z0-9_-]{0,31}$/', $name) === 1 && $name !== 's' ? $name : 'semantic';
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{count: int, label: string, buttonText: string, showDate: bool, showExcerpt: bool, showImage: bool}
     */
    public static function attributes(array $raw): array
    {
        $count = isset($raw['count']) && is_numeric($raw['count']) ? (int) $raw['count'] : 10;

        return [
            'count' => max(1, min(20, $count)),
            'label' => isset($raw['label']) && is_string($raw['label']) && $raw['label'] !== '' ? $raw['label'] : 'Search',
            'buttonText' => isset($raw['buttonText']) && is_string($raw['buttonText']) && $raw['buttonText'] !== '' ? $raw['buttonText'] : 'Search',
            'showDate' => !isset($raw['showDate']) || filter_var($raw['showDate'], FILTER_VALIDATE_BOOLEAN),
            'showExcerpt' => !isset($raw['showExcerpt']) || filter_var($raw['showExcerpt'], FILTER_VALIDATE_BOOLEAN),
            'showImage' => false,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<string, mixed>|null $get query arguments, defaults to $_GET
     * @param array<string, mixed>|null $server defaults to $_SERVER
     */
    public function render(array $raw, ?array $get = null, ?array $server = null): string
    {
        $attrs = self::attributes($raw);
        $get ??= $_GET;
        $server ??= $_SERVER;
        $param = self::param();
        $value = isset($get[$param]) && is_string($get[$param]) ? wp_unslash($get[$param]) : null;
        $id = 'post-embeddings-search-' . (++self::$instance);
        $html = '<section class="wp-block-post-embeddings-search post-embeddings-search">';
        $html .= '<form role="search" method="get" class="post-embeddings-search__form" action="' . esc_url(self::action()) . '">';
        $html .= self::hiddenInputs($param);
        $html .= '<label class="post-embeddings-search__label" for="' . esc_attr($id) . '">' . esc_html($attrs['label']) . '</label>';
        $html .= '<input class="post-embeddings-search__input" type="search" id="' . esc_attr($id) . '" name="' . esc_attr($param)
            . '" value="' . esc_attr($value === null ? '' : Search::normaliseQuery($value)) . '" minlength="' . Search::MIN_LEN
            . '" maxlength="' . Search::MAX_LEN . '" required>';
        $html .= '<button class="post-embeddings-search__button" type="submit">' . esc_html($attrs['buttonText']) . '</button>';
        $html .= '</form>';
        if ($value !== null) {
            $html .= $this->results($value, $attrs, $server);
        }

        return $html . '</section>';
    }

    /**
     * @param array{count: int, label: string, buttonText: string, showDate: bool, showExcerpt: bool, showImage: bool} $attrs
     * @param array<string, mixed> $server
     */
    private function results(string $value, array $attrs, array $server): string
    {
        try {
            $outcome = $this->search->search($value, $attrs['count'], $server);
        } catch (\Throwable $e) {
            $this->logger->error('search_error', ['class' => get_class($e)]);

            return '';
        }
        if ($outcome->mode === 'invalid') {
            return '<p class="post-embeddings-search__message">'
                . esc_html(sprintf('Please type between %d and %d characters.', Search::MIN_LEN, Search::MAX_LEN)) . '</p>';
        }
        $posts = array_map(static fn (Scored $s): WP_Post => $s->post, $outcome->results);
        $html = '<div class="post-embeddings-search__results" data-mode="' . esc_attr($outcome->mode) . '">';
        if ($outcome->mode === 'keyword') {
            $html .= '<h2 class="post-embeddings-search__heading">' . esc_html('Keyword results') . '</h2>';
        }
        if ($posts === []) {
            $html .= '<p class="post-embeddings-search__message">' . esc_html('No matching posts.') . '</p>';
        } else {
            $html .= Markup::list('search', $posts, $attrs);
        }
        if ($outcome->mode === 'semantic') {
            $html .= '<p class="post-embeddings-search__keyword"><a href="'
                . esc_url(add_query_arg('s', rawurlencode($outcome->query), home_url('/'))) . '">'
                . esc_html('Search by keyword instead') . '</a></p>';
        }

        return $html . '</div>';
    }

    private static function action(): string
    {
        if (is_singular()) {
            $link = get_permalink((int) get_queried_object_id());
            if (is_string($link) && $link !== '') {
                return (string) strtok($link, '?');
            }
        }

        return (string) strtok(home_url('/'), '?');
    }

    /** Keeps query arguments of plain permalinks, for example ?p=123. */
    private static function hiddenInputs(string $param): string
    {
        $link = is_singular() ? get_permalink((int) get_queried_object_id()) : home_url('/');
        $query = is_string($link) ? (string) parse_url($link, PHP_URL_QUERY) : '';
        if ($query === '') {
            return '';
        }
        parse_str($query, $args);
        $html = '';
        foreach ($args as $k => $v) {
            if ($k === $param || !is_string($v)) {
                continue;
            }
            $html .= '<input type="hidden" name="' . esc_attr((string) $k) . '" value="' . esc_attr($v) . '">';
        }

        return $html;
    }
}
