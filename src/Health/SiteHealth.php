<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Health;

use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;

/**
 * Site Health tests (case 70).
 */
final class SiteHealth
{
    public function __construct(
        private readonly ProviderResolver $providers,
        private readonly IndexStore $store,
        private readonly Sidecar $sidecar,
        private readonly Exposure $exposure,
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Clock $clock
    ) {
    }

    /**
     * @param array<string, mixed> $tests
     * @return array<string, mixed>
     */
    public function register(array $tests): array
    {
        $tests['direct'] = is_array($tests['direct'] ?? null) ? $tests['direct'] : [];
        foreach (['sidecar_age', 'drain', 'auth', 'exposure'] as $name) {
            $tests['direct']['post_embeddings_' . $name] = [
                'label' => 'Post embeddings: ' . str_replace('_', ' ', $name),
                'test' => fn (): array => $this->{'test' . str_replace('_', '', ucwords($name, '_'))}(),
            ];
        }

        return $tests;
    }

    /** @return array<string, mixed> */
    public function testSidecarAge(): array
    {
        $key = $this->providers->modelKey();
        if ($key === null || !$this->sidecar->enabled()) {
            return self::result('sidecar_age', 'good', 'The related-posts sidecar is not in use.', '');
        }
        $info = $this->sidecar->info($key);
        $limit = 3 * max(60, $this->settings->int('sidecar_max_age'));
        if ($info === null) {
            $ok = $this->countFor($key, IndexRow::OK);
            if ($ok === 0) {
                return self::result('sidecar_age', 'good', 'No posts are embedded yet, so there is no sidecar to check.', '');
            }

            return self::result('sidecar_age', 'recommended', 'The related-posts sidecar file is missing.', 'Requests use the slower table scan until it is rebuilt. Run `wp post-embeddings rebuild` and check the last build error on the status screen.');
        }
        if ((int) $info['age'] > $limit) {
            return self::result('sidecar_age', 'recommended', 'The related-posts sidecar is stale.', sprintf('It was built %d seconds ago; the limit is %d. Check that WP-Cron or a system cron runs.', (int) $info['age'], $limit));
        }

        return self::result('sidecar_age', 'good', 'The related-posts sidecar is fresh.', '');
    }

    /** @return array<string, mixed> */
    public function testDrain(): array
    {
        $key = $this->providers->modelKey();
        if ($key === null) {
            return self::result('drain', 'good', 'No embeddings provider is configured.', '');
        }
        $pending = $this->countFor($key, IndexRow::PENDING);
        $last = $this->state->get('last_drain');
        $at = is_array($last) && isset($last['at']) ? (int) $last['at'] : 0;
        if ($pending > 0 && $this->clock->now() - $at > 3600) {
            return self::result('drain', 'recommended', 'The embeddings queue has not run for an hour.', sprintf('%d posts are waiting. WP-Cron only runs on page views; a real cron calling `wp cron event run --due-now` fixes this.', $pending));
        }

        return self::result('drain', 'good', 'The embeddings queue is running.', '');
    }

    /** @return array<string, mixed> */
    public function testAuth(): array
    {
        if ($this->state->paused() === 'paused_auth') {
            return self::result('auth', 'critical', 'Embeddings are paused: the provider refused the credentials.', 'Check the API key, then save the settings or run `wp post-embeddings resume`.');
        }

        return self::result('auth', 'good', 'The embeddings provider accepts the credentials.', '');
    }

    /** @return array<string, mixed> */
    public function testExposure(): array
    {
        $key = $this->providers->modelKey();
        $result = $this->exposure->check(false, $key === null ? null : Sidecar::fileName($key));
        if ($result === 'exposed') {
            return self::result('exposure', 'critical', 'The embeddings sidecar can be downloaded from the web.', 'Vectors can leak information about the text they came from. Block the directory in the web server (the README shows an nginx rule) or move it outside the document root with POST_EMBEDDINGS_SIDECAR_DIR.');
        }
        if ($result === 'unknown') {
            return self::result('exposure', 'recommended', 'Could not verify that the embeddings sidecar is private.', 'The site could not request its own sidecar URL. Check by hand that the directory answers 403 or 404, or move it outside the document root.');
        }

        return self::result('exposure', 'good', 'The embeddings sidecar is not reachable from the web.', '');
    }

    private function countFor(string $key, string $status): int
    {
        try {
            return (int) ($this->store->counts()[$key][$status] ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, mixed> */
    private static function result(string $test, string $status, string $label, string $description): array
    {
        return [
            'label' => $label,
            'status' => $status,
            'badge' => ['label' => 'Post embeddings', 'color' => $status === 'good' ? 'blue' : ($status === 'critical' ? 'red' : 'orange')],
            'description' => '<p>' . esc_html($description === '' ? $label : $description) . '</p>',
            'actions' => '',
            'test' => 'post_embeddings_' . $test,
        ];
    }
}
