<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Query;

use IndianOpinion\PackedVectors\Exception\BudgetExceededException;
use IndianOpinion\PackedVectors\Exception\PackedVectorsError;
use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PostEmbeddings\Cache;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\VectorScan;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/**
 * Related posts for one post (cases 41 to 49). Costs nothing at the
 * provider, so it keeps serving with the kill switch on.
 */
final class Related
{
    public const TTL = 43200;
    public const SCAN_BUDGET = 0.3;
    public const MAX_HITS = 200;

    public function __construct(
        private readonly IndexStore $store,
        private readonly ProviderResolver $providers,
        private readonly VectorScan $sidecar,
        private readonly HitRanker $ranker,
        private readonly Cache $cache,
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Logger $logger
    ) {
    }

    /**
     * Normalised arguments: count 1 to 12, post types, min score.
     *
     * @param array<string, mixed> $args
     * @return array{count: int, post_types: list<string>, min_score: float}
     */
    public function args(int $postId, array $args): array
    {
        $args = apply_filters('post_embeddings_related_args', $args, $postId);
        $args = is_array($args) ? $args : [];
        $count = isset($args['count']) && is_numeric($args['count']) ? (int) $args['count'] : 4;
        $types = $args['post_types'] ?? null;
        if (!is_array($types) || $types === []) {
            $own = get_post_type($postId);
            $types = is_string($own) ? [$own] : ['post'];
        }
        $public = get_post_types(['public' => true]);
        $types = array_values(array_filter(array_map('strval', $types), static fn (string $t): bool => in_array($t, $public, true)));
        $min = isset($args['min_score']) && is_numeric($args['min_score'])
            ? (float) $args['min_score'] : $this->settings->float('min_score');

        return [
            'count' => max(1, min(12, $count)),
            'post_types' => $types === [] ? ['post'] : $types,
            'min_score' => max(-1.0, min(1.0, $min)),
        ];
    }

    /**
     * Related posts, best first. Empty when there is nothing semantic to
     * show; callers render their fallback.
     *
     * @param array<string, mixed> $args
     * @return list<Scored>
     * @throws PackedVectorsError|\Throwable on scanner or database failure (callers catch at the boundary)
     */
    public function find(int $postId, array $args = []): array
    {
        $provider = $this->providers->provider();
        if ($postId < 1 || $provider === null || !$this->store->ready()) {
            return [];
        }
        $a = $this->args($postId, $args);
        $key = $this->providers->modelKey() ?? '';
        $dim = $provider->dimensions();
        $row = $this->store->get($postId, $key);
        if ($row === null || $row->status !== IndexRow::OK) {
            return [];
        }
        $vector = VectorMath::unpack($row->vector, $dim);
        if ($vector === null) {
            return [];
        }
        $cacheKey = 'rel_' . md5(implode('|', [
            get_current_blog_id(),
            $postId,
            $key,
            $this->state->generation(),
            (string) $row->embeddedAt,
            $row->textHash,
            json_encode($a),
            $this->settings->float('duplicate_score'),
            $this->settings->int('max_age_days'),
            $this->settings->int('overfetch'),
        ]));
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached)) {
            $out = $this->hydrate($cached);
            if ($out !== null) {
                return $this->filterResults($out, $postId, $a);
            }
        }
        $k = min(self::MAX_HITS, $a['count'] * max(1, $this->settings->int('overfetch')));
        $options = new ScanOptions(minScore: $a['min_score'], timeBudgetSeconds: self::SCAN_BUDGET);
        try {
            $hits = $this->sidecar->top($key, $dim, $vector, $k, $options);
        } catch (BudgetExceededException $e) {
            $this->logger->warning('scan_budget', ['post' => $postId, 'scanned' => $e->scanned]);

            return [];
        }
        $maxAge = $this->settings->int('max_age_days');
        $source = get_post($postId);
        $notBefore = $maxAge > 0 && $source instanceof \WP_Post ? HitRanker::time($source) - $maxAge * 86400 : null;
        $ranked = $this->ranker->rank(
            $hits,
            $a['count'],
            $a['post_types'],
            $postId,
            $vector,
            $this->settings->float('duplicate_score'),
            $notBefore,
            $key,
            $dim
        );
        $this->cache->set($cacheKey, array_map(static fn (Scored $s): array => [$s->post->ID, $s->score], $ranked), self::TTL);

        return $this->filterResults($ranked, $postId, $a);
    }

    /**
     * @param list<Scored> $results
     * @param array{count: int, post_types: list<string>, min_score: float} $args
     * @return list<Scored>
     */
    private function filterResults(array $results, int $postId, array $args): array
    {
        $filtered = apply_filters('post_embeddings_related_results', $results, $postId, $args);
        if (!is_array($filtered)) {
            return $results;
        }

        return array_values(array_filter($filtered, static fn ($s): bool => $s instanceof Scored));
    }

    /**
     * @param array<mixed> $cached
     * @return list<Scored>|null null when a cached post has gone
     */
    private function hydrate(array $cached): ?array
    {
        $out = [];
        foreach ($cached as $pair) {
            if (!is_array($pair) || count($pair) !== 2) {
                return null;
            }
            $post = get_post((int) $pair[0]);
            if (!$post instanceof \WP_Post || $post->post_status !== 'publish' || $post->post_password !== '') {
                return null;
            }
            $out[] = new Scored($post, (float) $pair[1]);
        }

        return $out;
    }
}
