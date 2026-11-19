<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

/**
 * Suggested privacy-policy text (case 75).
 */
final class Privacy
{
    public static function text(): string
    {
        return '<p>' . esc_html(
            'This site sends the text of published posts to an embeddings provider to find related posts and to power '
            . 'semantic search. When you use the semantic search box, the words you type are sent to the same provider '
            . 'to compute a vector. This site does not store or log your search text; it keeps only the vector, under '
            . 'a one-way hash, for up to seven days. To limit abuse it counts searches per visitor under a salted hash '
            . 'of the IP address for ten minutes; the address itself is not stored.'
        ) . '</p>';
    }

    public static function register(): void
    {
        if (function_exists('wp_add_privacy_policy_content')) {
            wp_add_privacy_policy_content('Post embeddings', self::text());
        }
    }
}
