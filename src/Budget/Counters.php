<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Settings;

/**
 * Daily cap counters keyed by UTC date. A new date starts from zero on read,
 * so a missed cron can never leave a cap stuck. The counts live in a
 * CounterBackend (atomic options rows in production); the once-a-day cap
 * notes live in one option.
 */
final class Counters
{
    public const OPTION = 'post_embeddings_counters';

    private readonly CounterBackend $backend;

    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        private readonly Logger $logger,
        ?CounterBackend $backend = null
    ) {
        $this->backend = $backend ?? new OptionCounterBackend();
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
        $today = $this->today();

        return ['date' => $today] + $this->backend->read($today) + ['noted' => $this->noted()];
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

    /**
     * Counts posts and tokens against today's indexing caps before a call,
     * atomically: the counters move first and are given back when the total
     * crosses a cap, so two processes racing near a cap can both be refused
     * but can never both overshoot it.
     *
     * @phpstan-impure
     */
    public function claimIndex(int $posts, int $tokens): bool
    {
        $deltas = ['index_posts' => max(0, $posts), 'index_tokens' => max(0, $tokens)];
        $after = $this->backend->add($this->today(), $deltas);
        $tokenCap = $this->settings->int('daily_token_cap');
        if (
            $after['index_posts'] > $this->settings->int('daily_post_cap')
            || ($tokenCap > 0 && $after['index_tokens'] > $tokenCap)
        ) {
            $this->backend->add($this->today(), array_map(static fn (int $n): int => -$n, $deltas));

            return false;
        }

        return true;
    }

    /** Gives back a claim for a call that was not made or not billed. */
    public function releaseIndex(int $posts, int $tokens): void
    {
        $this->backend->add($this->today(), ['index_posts' => -max(0, $posts), 'index_tokens' => -max(0, $tokens)]);
    }

    /** Counts one fresh query embedding before the call, atomically (see claimIndex). */
    public function claimQuery(): bool
    {
        $after = $this->backend->add($this->today(), ['queries' => 1]);
        if ($after['queries'] > $this->settings->int('daily_query_cap')) {
            $this->backend->add($this->today(), ['queries' => -1]);

            return false;
        }

        return true;
    }

    /** Adds to the indexing counters; a negative token count corrects an estimate downwards. */
    public function addIndex(int $posts, int $tokens): void
    {
        $this->backend->add($this->today(), ['index_posts' => max(0, $posts), 'index_tokens' => $tokens]);
    }

    public function addQuery(): void
    {
        $this->backend->add($this->today(), ['queries' => 1]);
    }

    /**
     * Records a cap hit: log line and `post_embeddings_cap_reached` once per
     * limit per UTC day, never once per request.
     */
    public function noteCapReached(string $which, int $limit): void
    {
        $noted = $this->noted();
        if (!empty($noted[$which])) {
            return;
        }
        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) && ($raw['date'] ?? null) === $this->today() ? $raw : ['date' => $this->today()];
        $raw['noted'] = [$which => true] + $noted;
        update_option(self::OPTION, $raw, false);
        $code = $which === 'kill_switch' ? 'kill_switch' : 'cap_reached_' . $which;
        $this->logger->warning($code, ['limit' => $limit, 'date' => $this->today()]);
        do_action('post_embeddings_cap_reached', $which, $limit);
    }

    /** @return array<string, bool> */
    private function noted(): array
    {
        $raw = get_option(self::OPTION, []);
        if (!is_array($raw) || ($raw['date'] ?? null) !== $this->today() || !is_array($raw['noted'] ?? null)) {
            return [];
        }

        return array_map('boolval', $raw['noted']);
    }
}
