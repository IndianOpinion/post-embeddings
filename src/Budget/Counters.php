<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Settings;

/**
 * Daily cap counters in one option, keyed by UTC date. A new date resets
 * them on read, so a missed cron can never leave a cap stuck.
 */
final class Counters
{
    public const OPTION = 'post_embeddings_counters';

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger
    ) {
    }

    public function today(): string
    {
        return gmdate('Y-m-d', $this->clock->now());
    }

    /**
     * @return array{date: string, index_posts: int, index_tokens: int, queries: int, noted: array<string, bool>}
     *
     * @phpstan-impure
     */
    public function usage(): array
    {
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        if (($raw['date'] ?? null) !== $this->today()) {
            return ['date' => $this->today(), 'index_posts' => 0, 'index_tokens' => 0, 'queries' => 0, 'noted' => []];
        }

        return [
            'date' => $this->today(),
            'index_posts' => (int) ($raw['index_posts'] ?? 0),
            'index_tokens' => (int) ($raw['index_tokens'] ?? 0),
            'queries' => (int) ($raw['queries'] ?? 0),
            'noted' => is_array($raw['noted'] ?? null) ? array_map('boolval', $raw['noted']) : [],
        ];
    }

    /**
     * Posts that may still be sent today for indexing.
     *
     * @phpstan-impure
     */
    public function postsLeft(): int
    {
        return max(0, $this->settings->int('daily_post_cap') - $this->usage()['index_posts']);
    }

    /**
     * Tokens that may still be sent today, or null when the token cap is off.
     *
     * @phpstan-impure
     */
    public function tokensLeft(): ?int
    {
        $cap = $this->settings->int('daily_token_cap');
        if ($cap <= 0) {
            return null;
        }

        return max(0, $cap - $this->usage()['index_tokens']);
    }

    /** @phpstan-impure */
    public function queriesLeft(): int
    {
        return max(0, $this->settings->int('daily_query_cap') - $this->usage()['queries']);
    }

    public function addIndex(int $posts, int $tokens): void
    {
        $u = $this->usage();
        $u['index_posts'] += max(0, $posts);
        $u['index_tokens'] += max(0, $tokens);
        update_option(self::OPTION, $u, false);
    }

    public function addQuery(): void
    {
        $u = $this->usage();
        $u['queries']++;
        update_option(self::OPTION, $u, false);
    }

    /**
     * Records a cap hit: log line and `post_embeddings_cap_reached` once per
     * limit per UTC day, never once per request.
     */
    public function noteCapReached(string $which, int $limit): void
    {
        $u = $this->usage();
        if (!empty($u['noted'][$which])) {
            return;
        }
        $u['noted'][$which] = true;
        update_option(self::OPTION, $u, false);
        $code = $which === 'kill_switch' ? 'kill_switch' : 'cap_reached_' . $which;
        $this->logger->warning($code, ['limit' => $limit, 'date' => $u['date']]);
        do_action('post_embeddings_cap_reached', $which, $limit);
    }
}
