<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Provider;

use IndianOpinion\PostEmbeddings\ModelKey;
use IndianOpinion\PostEmbeddings\Settings;

/**
 * Resolves the active adapter: the HTTP adapter when configured, else null
 * (the plugin idles), then the `post_embeddings_provider` filter.
 */
final class ProviderResolver
{
    private ?EmbeddingProvider $resolved = null;

    private bool $done = false;

    public function __construct(private readonly Settings $settings)
    {
    }

    public function provider(): ?EmbeddingProvider
    {
        if ($this->done) {
            return $this->resolved;
        }
        $settings = $this->settings->all();
        $default = null;
        $baseUrl = (string) ($settings['base_url'] ?? '');
        $model = (string) ($settings['model'] ?? '');
        $dims = (int) ($settings['dimensions'] ?? 0);
        if ($baseUrl !== '' && $model !== '' && $dims > 0 && HttpEmbeddingProvider::validateBaseUrl($baseUrl) === null) {
            $headers = apply_filters('post_embeddings_extra_headers', []);
            $default = new HttpEmbeddingProvider(
                $baseUrl,
                $model,
                $dims,
                $this->settings->apiKey(),
                (int) $settings['max_input_chars'],
                (int) $settings['max_batch'],
                (bool) $settings['send_dimensions'],
                30,
                is_array($headers) ? array_map('strval', $headers) : []
            );
        }
        $filtered = apply_filters('post_embeddings_provider', $default, $settings);
        $provider = $filtered instanceof EmbeddingProvider ? $filtered : null;
        if ($provider !== null && !self::valid($provider)) {
            $provider = null;
        }
        $this->resolved = $provider;
        $this->done = true;

        return $provider;
    }

    public function modelKey(): ?string
    {
        $p = $this->provider();

        return $p === null ? null : ModelKey::of($p);
    }

    public function reset(): void
    {
        $this->done = false;
        $this->resolved = null;
    }

    private static function valid(EmbeddingProvider $p): bool
    {
        return preg_match('/^[a-z0-9_-]{1,32}$/', $p->id()) === 1
            && $p->model() !== '' && strlen($p->model()) <= 128
            && $p->dimensions() >= 1 && $p->dimensions() <= 65535
            && $p->maxInputChars() >= 100
            && $p->maxBatch() >= 1 && $p->maxBatch() <= 256;
    }
}
