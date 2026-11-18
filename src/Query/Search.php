<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Query;

use IndianOpinion\PackedVectors\ScanOptions;
use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Budget\SpendGate;
use IndianOpinion\PostEmbeddings\Cache;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Provider\TimeoutAware;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\VectorScan;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;
use WP_Post;

/**
 * Semantic search (cases 55 to 58). Any reason the query cannot be embedded
 * or scanned gives keyword results, never an error. Query text is never
 * stored or logged; only its vector is cached, under a hash.
 */
final class Search
{
    public const MIN_LEN = 3;
    public const MAX_LEN = 200;
    public const VECTOR_TTL = 604800;
    public const TIMEOUT = 5;

    public function __construct(
        private readonly IndexStore $store,
        private readonly ProviderResolver $providers,
        private readonly VectorScan $sidecar,
        private readonly HitRanker $ranker,
        private readonly RateLimiter $limiter,
        private readonly Counters $counters,
        private readonly SpendGate $meter,
        private readonly TokenEstimator $tokens,
        private readonly Cache $cache,
        private readonly Settings $settings,
        private readonly Logger $logger
    ) {
    }

    public static function normaliseQuery(string $raw): string
    {
        return EmbeddingText::cleanPlain($raw);
    }

    public static function valid(string $query): bool
    {
        $len = mb_strlen($query, 'UTF-8');

        return $len >= self::MIN_LEN && $len <= self::MAX_LEN;
    }

    /**
     * @param array<string, mixed> $server for the client address, usually $_SERVER
     */
    public function search(string $raw, int $count = 10, array $server = []): SearchOutcome
    {
        $query = self::normaliseQuery($raw);
        $count = max(1, min(20, $count));
        if (!self::valid($query)) {
            return new SearchOutcome('invalid', [], $query, 'length');
        }
        try {
            $semantic = $this->semantic($query, $count, $server);
        } catch (\Throwable $e) {
            $this->logger->error('search_error', ['class' => get_class($e)]);
            $semantic = 'error';
        }
        if ($semantic instanceof SearchOutcome) {
            return $this->filtered($semantic);
        }
        $this->logger->info('search_fallback', ['reason' => $semantic]);

        return $this->filtered(new SearchOutcome('keyword', $this->keyword($query, $count), $query, $semantic));
    }

    /**
     * The semantic outcome, or the reason code for falling back.
     *
     * @param array<string, mixed> $server
     */
    private function semantic(string $query, int $count, array $server): SearchOutcome|string
    {
        $provider = $this->providers->provider();
        if ($provider === null || !$this->store->ready()) {
            return 'unavailable';
        }
        $key = $this->providers->modelKey() ?? '';
        $dim = $provider->dimensions();
        $vector = $this->queryVector($provider, $key, $query, $server);
        if (is_string($vector)) {
            return $vector;
        }
        $k = min(Related::MAX_HITS, $count * max(1, $this->settings->int('overfetch')));
        $options = new ScanOptions(minScore: $this->settings->float('search_min_score'), timeBudgetSeconds: Related::SCAN_BUDGET);
        try {
            $hits = $this->sidecar->top($key, $dim, $vector, $k, $options);
        } catch (\IndianOpinion\PackedVectors\Exception\BudgetExceededException $e) {
            $this->logger->warning('scan_budget', ['scanned' => $e->scanned]);

            return 'scan_budget';
        }
        $ranked = $this->ranker->rank($hits, $count, $this->settings->postTypes());

        return new SearchOutcome('semantic', $ranked, $query);
    }

    /**
     * A cached or fresh query vector, or the reason code for not having one.
     *
     * @param array<string, mixed> $server
     * @return list<float>|string
     */
    public function queryVector(EmbeddingProvider $provider, string $key, string $query, array $server = []): array|string
    {
        $cacheKey = 'qv_' . hash('sha256', $key . "\n" . mb_strtolower($query, 'UTF-8'));
        $cached = $this->cache->get($cacheKey);
        if (is_string($cached)) {
            $v = VectorMath::unpack($cached, $provider->dimensions());
            if ($v !== null) {
                return $v;
            }
        }
        if ($this->settings->killSwitch() !== null) {
            return 'kill_switch';
        }
        if (!$this->settings->bool('search_enabled')) {
            return 'search_disabled';
        }
        if (!$this->limiter->allow(RateLimiter::clientKey(RateLimiter::clientAddress($server)))) {
            return 'rate_limit';
        }
        if ($this->counters->queriesLeft() < 1) {
            $this->counters->noteCapReached('queries', $this->settings->int('daily_query_cap'));

            return 'cap_queries';
        }
        $estimate = $this->tokens->estimate($query);
        $reservation = $this->meter->reserve('query', $provider->id(), $provider->model(), $estimate);
        if (!$reservation->allowed) {
            return 'spend_meter';
        }
        if ($this->counters->queriesLeft() < 1) {
            $this->meter->release($reservation);

            return 'cap_queries';
        }
        $caller = $provider instanceof TimeoutAware ? $provider->withTimeout(self::TIMEOUT) : $provider;
        $this->counters->addQuery();
        try {
            $result = $caller->embed([$query]);
        } catch (\Throwable $e) {
            $this->meter->record($reservation, 'error', $estimate);
            $this->logger->warning('search_provider', ['class' => (new \ReflectionClass($e))->getShortName()]);

            return 'provider_error';
        }
        $this->meter->record($reservation, 'ok', $result->inputTokens ?? $estimate);
        $vector = $result->vector(0);
        if (VectorMath::problem($vector, $provider->dimensions()) !== null) {
            return 'bad_vector';
        }
        /** @var list<int|float> $vector */
        $unit = VectorMath::normalise($vector);
        $this->cache->set($cacheKey, VectorMath::pack($unit), self::VECTOR_TTL);

        return $unit;
    }

    /**
     * Core keyword results for the same text.
     *
     * @return list<Scored>
     */
    public function keyword(string $query, int $count): array
    {
        try {
            $q = new \WP_Query([
                's' => $query,
                'post_type' => $this->settings->postTypes(),
                'post_status' => 'publish',
                'has_password' => false,
                'posts_per_page' => $count,
                'no_found_rows' => true,
                'ignore_sticky_posts' => true,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('keyword_error', ['class' => get_class($e)]);

            return [];
        }
        $out = [];
        foreach ($q->posts as $post) {
            if ($post instanceof WP_Post) {
                $out[] = new Scored($post, 0.0);
            }
        }

        return $out;
    }

    private function filtered(SearchOutcome $o): SearchOutcome
    {
        $results = apply_filters('post_embeddings_search_results', $o->results, $o->query, $o->mode);
        if (!is_array($results)) {
            return $o;
        }

        return new SearchOutcome($o->mode, array_values(array_filter($results, static fn ($s): bool => $s instanceof Scored)), $o->query, $o->reason);
    }
}
