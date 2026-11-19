<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Query;

use IndianOpinion\PostEmbeddings\Cache;
use IndianOpinion\PostEmbeddings\Clock;

/**
 * Fresh query embeddings per client in a fixed window. The key is a salted
 * hash of REMOTE_ADDR; no raw address is stored. Proxy headers count only
 * when the site opts in with `post_embeddings_trust_proxy_headers`.
 */
final class RateLimiter
{
    public function __construct(private readonly Cache $cache, private readonly Clock $clock)
    {
    }

    /** @return array{limit: int, window: int} */
    public function rule(): array
    {
        $rule = apply_filters('post_embeddings_rate_limit', ['limit' => 10, 'window' => 600]);
        $limit = is_array($rule) && isset($rule['limit']) ? (int) $rule['limit'] : 10;
        $window = is_array($rule) && isset($rule['window']) ? (int) $rule['window'] : 600;

        return ['limit' => max(0, $limit), 'window' => max(1, $window)];
    }

    /**
     * @param array<string, mixed> $server usually $_SERVER
     */
    public static function clientAddress(array $server): string
    {
        $remote = isset($server['REMOTE_ADDR']) && is_string($server['REMOTE_ADDR']) ? trim($server['REMOTE_ADDR']) : '';
        if ((bool) apply_filters('post_embeddings_trust_proxy_headers', false)) {
            $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
            if (is_string($forwarded) && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : 'unknown';
    }

    public static function clientKey(string $address): string
    {
        return substr(hash_hmac('sha256', $address, wp_salt('nonce')), 0, 32);
    }

    /** Counts one fresh embedding for the client; false when over the limit. */
    public function allow(string $clientKey): bool
    {
        $rule = $this->rule();
        $key = 'rl_' . $clientKey;
        $now = $this->clock->now();
        $entry = $this->cache->get($key);
        if (!is_array($entry) || !isset($entry['start'], $entry['count']) || $now - (int) $entry['start'] >= $rule['window']) {
            $entry = ['start' => $now, 'count' => 0];
        }
        if ((int) $entry['count'] >= $rule['limit']) {
            return false;
        }
        $entry['count'] = (int) $entry['count'] + 1;
        $this->cache->set($key, $entry, $rule['window']);

        return true;
    }
}
