<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

use IndianOpinion\PostEmbeddings\Provider\HttpEmbeddingProvider;

/**
 * The plugin's settings: defaults, validation, constants and the kill switch.
 */
final class Settings
{
    public const OPTION = 'post_embeddings_settings';

    /** Settings that, when changed, clear an authentication pause. */
    public const ADAPTER_KEYS = ['base_url', 'model', 'dimensions', 'api_key', 'send_dimensions', 'max_input_chars', 'max_batch'];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    /**
     * Defaults for every setting, after the `post_embeddings_default_settings` filter.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [
            'enabled' => true,
            'search_enabled' => true,
            'post_types' => ['post'],
            'min_chars' => 40,
            'batch_size' => 16,
            'drain_interval' => 300,
            'drain_max_batches' => 4,
            'max_attempts' => 8,
            'daily_post_cap' => 500,
            'daily_token_cap' => 0,
            'daily_query_cap' => 1000,
            'backfill_cap_ceiling' => 5000,
            'min_score' => 0.30,
            'search_min_score' => 0.25,
            'duplicate_score' => 0.98,
            'overfetch' => 3,
            'max_age_days' => 0,
            'sidecar_max_age' => 3600,
            'search_no_store' => false,
            'log' => true,
            'base_url' => '',
            'model' => '',
            'dimensions' => 0,
            'api_key' => '',
            'max_input_chars' => 8000,
            'max_batch' => 64,
            'send_dimensions' => false,
        ];
        $filtered = apply_filters('post_embeddings_default_settings', $defaults);

        return is_array($filtered) ? array_merge($defaults, $filtered) : $defaults;
    }

    /**
     * Every setting, stored values over defaults.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $stored = get_option(self::OPTION, []);
            $this->cache = array_merge(self::defaults(), is_array($stored) ? $stored : []);
        }

        return $this->cache;
    }

    public function refresh(): void
    {
        $this->cache = null;
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function int(string $key): int
    {
        $value = $this->get($key);

        return is_numeric($value) ? (int) $value : 0;
    }

    public function float(string $key): float
    {
        $value = $this->get($key);

        return is_numeric($value) ? (float) $value : 0.0;
    }

    public function bool(string $key): bool
    {
        return (bool) $this->get($key);
    }

    /**
     * Post types to embed, after the `post_embeddings_post_types` filter.
     *
     * @return list<string>
     */
    public function postTypes(): array
    {
        $types = $this->get('post_types');
        $types = is_array($types) ? $types : ['post'];
        $types = apply_filters('post_embeddings_post_types', $types);
        $out = [];
        foreach (is_array($types) ? $types : [] as $type) {
            if (is_string($type) && preg_match('/^[a-z0-9_-]{1,20}$/', $type) === 1) {
                $out[] = $type;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Where the kill switch comes from when it is on: `constant`, `setting`, or null when off.
     */
    public function killSwitch(): ?string
    {
        if (self::constantDisabled()) {
            return 'constant';
        }

        return $this->bool('enabled') ? null : 'setting';
    }

    public static function constantDisabled(): bool
    {
        return defined('POST_EMBEDDINGS_DISABLED') && constant('POST_EMBEDDINGS_DISABLED') === true;
    }

    public static function apiKeyConstantDefined(): bool
    {
        return defined('POST_EMBEDDINGS_API_KEY') && is_string(constant('POST_EMBEDDINGS_API_KEY'))
            && constant('POST_EMBEDDINGS_API_KEY') !== '';
    }

    public function apiKey(): string
    {
        if (self::apiKeyConstantDefined()) {
            return (string) constant('POST_EMBEDDINGS_API_KEY');
        }
        $key = $this->get('api_key');

        return is_string($key) ? $key : '';
    }

    /**
     * Validates input from the settings screen or `kill`. Unknown keys are
     * dropped, numbers clamped, post types checked against registered public
     * types, the base URL checked for scheme and host. An empty api_key keeps
     * the stored key.
     *
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>, 1: list<string>} sanitised settings and error messages
     */
    public function sanitize(array $input): array
    {
        $current = $this->all();
        $out = $current;
        $errors = [];
        $bools = ['enabled', 'search_enabled', 'search_no_store', 'log', 'send_dimensions'];
        foreach ($bools as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }
        $ints = [
            'min_chars' => [1, 10000],
            'batch_size' => [1, 256],
            'drain_interval' => [60, 3600],
            'drain_max_batches' => [1, 50],
            'max_attempts' => [1, 50],
            'daily_post_cap' => [0, 1000000],
            'daily_token_cap' => [0, 1000000000],
            'daily_query_cap' => [0, 1000000],
            'backfill_cap_ceiling' => [1, 1000000],
            'overfetch' => [1, 20],
            'max_age_days' => [0, 36500],
            'sidecar_max_age' => [60, 604800],
            'dimensions' => [0, 65535],
            'max_input_chars' => [100, 1000000],
            'max_batch' => [1, 256],
        ];
        foreach ($ints as $key => [$min, $max]) {
            if (array_key_exists($key, $input) && is_numeric($input[$key])) {
                $out[$key] = max($min, min($max, (int) $input[$key]));
            }
        }
        $floats = ['min_score' => [-1.0, 1.0], 'search_min_score' => [-1.0, 1.0], 'duplicate_score' => [0.0, 1.01]];
        foreach ($floats as $key => [$min, $max]) {
            if (array_key_exists($key, $input) && is_numeric($input[$key])) {
                $out[$key] = max($min, min($max, (float) $input[$key]));
            }
        }
        if (array_key_exists('post_types', $input)) {
            $given = is_array($input['post_types']) ? $input['post_types'] : explode(',', (string) $input['post_types']);
            $public = get_post_types(['public' => true]);
            $types = [];
            foreach ($given as $type) {
                $type = trim((string) $type);
                if ($type !== '' && in_array($type, $public, true)) {
                    $types[] = $type;
                }
            }
            if ($types === []) {
                $errors[] = 'No registered public post type given; post types unchanged.';
            } else {
                $out['post_types'] = array_values(array_unique($types));
            }
        }
        if (array_key_exists('base_url', $input)) {
            $url = trim((string) $input['base_url']);
            if ($url === '') {
                $out['base_url'] = '';
            } else {
                $problem = HttpEmbeddingProvider::validateBaseUrl($url);
                if ($problem === null) {
                    $out['base_url'] = rtrim($url, '/');
                } else {
                    $errors[] = $problem;
                }
            }
        }
        if (array_key_exists('model', $input)) {
            $model = trim((string) $input['model']);
            if ($model === '' || preg_match('/^[\x21-\x7e]{1,128}$/', $model) === 1) {
                $out['model'] = $model;
            } else {
                $errors[] = 'The model name must be 1 to 128 printable ASCII characters without spaces.';
            }
        }
        if (array_key_exists('api_key', $input)) {
            $key = trim((string) $input['api_key']);
            if ($key !== '') {
                $out['api_key'] = $key;
            }
        }

        return [$out, $errors];
    }

    /**
     * Saves validated settings. Returns the error messages.
     *
     * Saving any adapter setting clears an authentication pause. A change of
     * post types asks the next drain to recheck eligibility.
     *
     * @param array<string, mixed> $input
     * @return list<string>
     */
    public function save(array $input, State $state): array
    {
        $before = $this->all();
        [$after, $errors] = $this->sanitize($input);
        $stored = array_intersect_key($after, self::defaults());
        update_option(self::OPTION, $stored, true);
        $this->refresh();
        foreach (self::ADAPTER_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $state->clearPause();
                break;
            }
        }
        if (($before['post_types'] ?? null) !== ($after['post_types'] ?? null)) {
            $state->set('recheck_cursor', 0);
        }

        return $errors;
    }
}
