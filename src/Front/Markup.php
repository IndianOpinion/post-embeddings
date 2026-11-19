<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Front;

use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use WP_Post;

/**
 * Escaped list markup shared by both blocks. Readers never see scores.
 */
final class Markup
{
    private function __construct()
    {
    }

    /**
     * @param list<WP_Post> $posts
     * @param array{showDate: bool, showExcerpt: bool, showImage: bool} $show
     */
    public static function list(string $block, array $posts, array $show): string
    {
        $html = '<ul class="post-embeddings-' . $block . '__list">';
        foreach ($posts as $post) {
            $link = (string) get_permalink($post);
            $title = self::title($post);
            $html .= '<li class="post-embeddings-' . $block . '__item">';
            if ($show['showImage']) {
                $html .= self::image($block, $post, $link);
            }
            $html .= '<a class="post-embeddings-' . $block . '__link" href="' . esc_url($link) . '">' . esc_html($title) . '</a>';
            if ($show['showDate']) {
                $html .= ' <time class="post-embeddings-' . $block . '__date" datetime="'
                    . esc_attr((string) get_post_time('c', true, $post)) . '">'
                    . esc_html((string) get_the_date('', $post)) . '</time>';
            }
            if ($show['showExcerpt']) {
                $excerpt = self::excerpt($post);
                if ($excerpt !== '') {
                    $html .= '<p class="post-embeddings-' . $block . '__excerpt">' . esc_html($excerpt) . '</p>';
                }
            }
            $html .= '</li>';
        }

        return $html . '</ul>';
    }

    public static function title(WP_Post $post): string
    {
        $title = EmbeddingText::cleanPlain($post->post_title);

        return $title === '' ? '#' . $post->ID : $title;
    }

    /** Manual excerpt or the start of the cleaned content, about 30 words. */
    public static function excerpt(WP_Post $post): string
    {
        $text = $post->post_excerpt !== '' ? EmbeddingText::cleanPlain($post->post_excerpt) : EmbeddingText::cleanContent($post->post_content);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];
        if (count($words) <= 30) {
            return implode(' ', $words);
        }

        return implode(' ', array_slice($words, 0, 30)) . ' ...';
    }

    private static function image(string $block, WP_Post $post, string $link): string
    {
        $thumb = (int) get_post_thumbnail_id($post);
        if ($thumb < 1) {
            return '';
        }
        $src = wp_get_attachment_image_src($thumb, 'thumbnail');
        if (!is_array($src) || !isset($src[0]) || $src[0] === '') {
            return '';
        }
        $alt = get_post_meta($thumb, '_wp_attachment_image_alt', true);
        $alt = is_string($alt) ? trim(wp_strip_all_tags($alt)) : '';
        $size = '';
        if (isset($src[1], $src[2]) && (int) $src[1] > 0 && (int) $src[2] > 0) {
            $size = ' width="' . (int) $src[1] . '" height="' . (int) $src[2] . '"';
        }

        return '<a class="post-embeddings-' . $block . '__image" href="' . esc_url($link) . '" tabindex="-1" aria-hidden="true">'
            . '<img src="' . esc_url((string) $src[0]) . '" alt="' . esc_attr($alt) . '" loading="lazy" decoding="async"' . $size . '></a>';
    }
}
