<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Front;

use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Query\Search;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;
use WP_Post;

/**
 * Blocks, shortcodes, the private cache header (case 59) and the opt-in
 * core search reordering (case 60).
 */
final class Hooks
{
    /** @var callable(string): void */
    private $header;

    /**
     * @param callable(string): void|null $header
     */
    public function __construct(
        private readonly RelatedRenderer $related,
        private readonly SearchRenderer $search,
        private readonly Search $searchService,
        private readonly ProviderResolver $providers,
        private readonly IndexStore $store,
        private readonly Settings $settings,
        private readonly Logger $logger,
        private readonly string $pluginDir,
        ?callable $header = null
    ) {
        $this->header = $header ?? static function (string $line): void {
            if (!headers_sent()) {
                header($line);
            }
        };
    }

    public function registerBlocks(): void
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        $editor = $this->pluginDir . '/assets/editor.js';
        if (is_file($editor)) {
            wp_register_script(
                'post-embeddings-editor',
                plugins_url('assets/editor.js', $this->pluginDir . '/post-embeddings.php'),
                ['wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-api-fetch', 'wp-data', 'wp-i18n'],
                (string) filemtime($editor),
                true
            );
        }
        register_block_type($this->pluginDir . '/blocks/related', [
            'render_callback' => fn (array $attrs): string => $this->related->render($attrs),
        ]);
        register_block_type($this->pluginDir . '/blocks/search', [
            'render_callback' => fn (array $attrs): string => $this->search->render($attrs),
        ]);
    }

    public function registerShortcodes(): void
    {
        add_shortcode('post_embeddings_related', fn ($atts): string => $this->related->render(self::shortcodeAtts($atts)));
        add_shortcode('post_embeddings_search', fn ($atts): string => $this->search->render(self::shortcodeAtts($atts)));
    }

    /**
     * Shortcode attributes are lower-cased by WordPress; map them back to
     * the block's camelCase names.
     *
     * @return array<string, mixed>
     */
    public static function shortcodeAtts(mixed $atts): array
    {
        $map = [
            'posttypes' => 'postTypes', 'post_types' => 'postTypes', 'showdate' => 'showDate', 'show_date' => 'showDate',
            'showexcerpt' => 'showExcerpt', 'show_excerpt' => 'showExcerpt', 'showimage' => 'showImage',
            'show_image' => 'showImage', 'minscore' => 'minScore', 'min_score' => 'minScore',
            'buttontext' => 'buttonText', 'button_text' => 'buttonText',
        ];
        $out = [];
        foreach (is_array($atts) ? $atts : [] as $k => $v) {
            $out[$map[strtolower((string) $k)] ?? (string) $k] = $v;
        }

        return $out;
    }

    /**
     * Sends `Cache-Control: private, max-age=0` on requests that carry the
     * search parameter, only when `search_no_store` is on.
     *
     * @param array<string, mixed>|null $get
     */
    public function cacheHeaders(?array $get = null): bool
    {
        $get ??= $_GET;
        if (!$this->settings->bool('search_no_store') || !isset($get[SearchRenderer::param()])) {
            return false;
        }
        ($this->header)('Cache-Control: private, max-age=0');

        return true;
    }

    /**
     * `the_posts` on the main search query: reorders by semantic score when
     * `post_embeddings_replace_core_search` returns true. Posts without a
     * vector keep their order after the scored ones. Any failure leaves core
     * order untouched.
     *
     * @param array<mixed> $posts
     * @param array<string, mixed> $server
     * @return array<mixed>
     */
    public function reorderCoreSearch(array $posts, mixed $query, array $server = []): array
    {
        if (!$query instanceof \WP_Query || !$query->is_main_query() || !$query->is_search() || count($posts) < 2) {
            return $posts;
        }
        if (!(bool) apply_filters('post_embeddings_replace_core_search', false)) {
            return $posts;
        }
        try {
            $provider = $this->providers->provider();
            $key = $this->providers->modelKey();
            if ($provider === null || $key === null) {
                return $posts;
            }
            $text = Search::normaliseQuery((string) $query->get('s'));
            if (!Search::valid($text)) {
                return $posts;
            }
            $vector = $this->searchService->queryVector($provider, $key, $text, $server === [] ? $_SERVER : $server);
            if (is_string($vector)) {
                $this->logger->info('search_fallback', ['reason' => $vector, 'where' => 'core']);

                return $posts;
            }
            $ids = [];
            foreach ($posts as $p) {
                if ($p instanceof WP_Post) {
                    $ids[] = $p->ID;
                }
            }
            $rows = $this->store->getMany($ids, $key);
            $scored = [];
            $rest = [];
            foreach ($posts as $i => $p) {
                $v = $p instanceof WP_Post && isset($rows[$p->ID]) ? VectorMath::unpack($rows[$p->ID]->vector, $provider->dimensions()) : null;
                if ($v === null) {
                    $rest[] = $p;
                } else {
                    $scored[] = [VectorMath::cosine($vector, $v), $i, $p];
                }
            }
            usort($scored, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

            return array_merge(array_map(static fn (array $s): mixed => $s[2], $scored), $rest);
        } catch (\Throwable $e) {
            $this->logger->error('core_search_error', ['class' => get_class($e)]);

            return $posts;
        }
    }
}
