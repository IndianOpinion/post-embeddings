<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Posts\PostSource;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Sidecar\Directory;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Store\Schema;

/**
 * Everything the status screen, `wp post-embeddings status` and the status
 * route show. No secrets: the API key never appears.
 */
final class StatusReport
{
    public function __construct(
        private readonly ProviderResolver $providers,
        private readonly IndexStore $store,
        private readonly PostSource $posts,
        private readonly Counters $counters,
        private readonly Sidecar $sidecar,
        private readonly Directory $directory,
        private readonly Settings $settings,
        private readonly State $state
    ) {
    }

    /** @return array<string, mixed> */
    public function collect(): array
    {
        $provider = $this->providers->provider();
        $key = $this->providers->modelKey();
        $counts = [];
        $tableError = null;
        if ($this->store->ready()) {
            try {
                $counts = $this->store->counts();
            } catch (\Throwable $e) {
                $tableError = 'query_failed';
            }
        } else {
            $tableError = 'missing';
        }
        $current = $key !== null ? ($counts[$key] ?? []) : [];
        $usage = $this->counters->usage();
        $kill = $this->settings->killSwitch();

        return [
            'provider' => $provider === null ? null : $provider->id(),
            'model' => $provider === null ? null : $provider->model(),
            'model_key' => $key,
            'kill_switch' => ['on' => $kill !== null, 'source' => $kill],
            'search_enabled' => $this->settings->bool('search_enabled'),
            'paused' => $this->state->paused(),
            'table' => $tableError ?? 'ok',
            'schema_missing' => Schema::missingColumns(),
            'counts' => $counts,
            'current' => array_merge(array_fill_keys(IndexRow::STATUSES, 0), $current),
            'progress' => [
                'embedded' => (int) ($current[IndexRow::OK] ?? 0),
                'eligible' => $this->posts->count($this->settings->postTypes()),
            ],
            'caps' => [
                'date' => $usage['date'],
                'index_posts' => ['used' => $usage['index_posts'], 'limit' => $this->settings->int('daily_post_cap')],
                'index_tokens' => ['used' => $usage['index_tokens'], 'limit' => $this->settings->int('daily_token_cap')],
                'queries' => ['used' => $usage['queries'], 'limit' => $this->settings->int('daily_query_cap')],
            ],
            'sidecar' => [
                'enabled' => $this->sidecar->enabled(),
                'directory' => $this->directory->path(),
                'protected' => $this->directory->protectedNow(),
                'file' => $key === null ? null : $this->sidecar->info($key),
                'last_build' => $this->state->get('last_build'),
                'error' => $this->state->get('sidecar_error'),
            ],
            'last_drain' => $this->state->get('last_drain'),
            'exposure' => $this->state->get('exposure'),
        ];
    }
}
