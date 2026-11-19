<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Text;

use WP_Post;

/**
 * Builds the text sent to the provider for a post (cases 7 to 11).
 *
 * The `the_content` filter chain never runs: no shortcode is expanded and no
 * embed is fetched. Nothing here assumes ASCII.
 */
final class EmbeddingText
{
    /** How far back from the limit a whitespace cut may move. */
    public const CUT_WINDOW = 200;

    /**
     * Title, a blank line, the manual excerpt, a blank line, the content.
     * Empty parts are left out. Filtered by `post_embeddings_text`.
     */
    public function build(WP_Post $post): string
    {
        $parts = [];
        foreach ([$post->post_title, $post->post_excerpt, $post->post_content] as $i => $raw) {
            $clean = $i === 2 ? self::cleanContent((string) $raw) : self::cleanPlain((string) $raw);
            if ($clean !== '') {
                $parts[] = $clean;
            }
        }
        $text = implode("\n\n", $parts);
        $filtered = apply_filters('post_embeddings_text', $text, $post);

        if (!is_string($filtered) || $filtered === $text) {
            return $text;
        }

        return self::normalise($filtered);
    }

    /** Content: block comments, shortcodes and tags removed, entities decoded. */
    public static function cleanContent(string $html): string
    {
        $html = (string) preg_replace('/<!--.*?-->/su', ' ', $html);
        $html = strip_shortcodes($html);
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/isu', ' ', $html);

        return self::cleanPlain($html);
    }

    /** Tags removed, entities decoded, whitespace collapsed, NFC. */
    public static function cleanPlain(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = (string) preg_replace('/<[^>]*>/u', ' ', $text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return self::normalise($text);
    }

    /**
     * NFC when intl is present; control characters become spaces; runs of
     * whitespace become one space. Letters, marks and numbers in every
     * script are kept as they are.
     */
    public static function normalise(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        if (class_exists(\Normalizer::class)) {
            $nfc = \Normalizer::normalize($text, \Normalizer::FORM_C);
            if (is_string($nfc)) {
                $text = $nfc;
            }
        }
        $text = (string) preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $text);
        $text = (string) preg_replace('/[\s\p{Z}]+/u', ' ', $text);

        return trim($text);
    }

    /**
     * Cuts to $limit characters, at the last whitespace in the final 200
     * characters when there is one. Never splits a multibyte character.
     */
    public static function truncate(string $text, int $limit): string
    {
        $limit = max(1, $limit);
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return $text;
        }
        $head = mb_substr($text, 0, $limit, 'UTF-8');
        $windowStart = max(0, $limit - self::CUT_WINDOW);
        $tail = mb_substr($head, $windowStart, null, 'UTF-8');
        if (preg_match_all('/\s/u', $tail, $m, PREG_OFFSET_CAPTURE) > 0) {
            $last = $m[0][count($m[0]) - 1];
            $byteOffset = $last[1];
            $chars = mb_strlen(substr($tail, 0, $byteOffset), 'UTF-8');
            $cut = rtrim(mb_substr($head, 0, $windowStart + $chars, 'UTF-8'));
            if ($cut !== '') {
                return $cut;
            }
        }

        return $head;
    }

    /** SHA-256 of the normalised text joined with the model key. */
    public static function hash(string $text, string $modelKey): string
    {
        return hash('sha256', $text . "\n" . $modelKey);
    }

    /** Empty text, or shorter than the minimum, is not worth embedding. */
    public static function isEmpty(string $text, int $minChars): bool
    {
        return $text === '' || mb_strlen($text, 'UTF-8') < $minChars;
    }
}
