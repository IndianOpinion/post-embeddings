<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\ModelKey;
use IndianOpinion\PostEmbeddings\Posts\Eligibility;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;
use WP_Post;

/**
 * The background drain (cases 12 to 15, 19): pending rows, oldest first, in
 * batches, under the kill switch, the caps and the spend meter, inside a
 * wall-time limit, under a lock.
 */
final class Drain
{
    public const HOOK = 'post_embeddings_drain';
    public const WALL_SECONDS = 20.0;
    public const RECHECK_PAGE = 500;

    public function __construct(
        private readonly IndexStore $store,
        private readonly ProviderResolver $providers,
        private readonly Eligibility $eligibility,
        private readonly EmbeddingText $text,
        private readonly TokenEstimator $tokens,
        private readonly Embedder $embedder,
        private readonly Counters $counters,
        private readonly Sidecar $sidecar,
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly DrainLock $lock
    ) {
    }

    /**
     * Runs one drain. Returns a report: result code and counts.
     *
     * @return array{result: string, batches: int, sent: int, stored: int, errors: int, changed: int, at: int}
     */
    public function run(): array
    {
        $report = ['result' => 'ok', 'batches' => 0, 'sent' => 0, 'stored' => 0, 'errors' => 0, 'changed' => 0, 'at' => $this->clock->now()];
        $provider = $this->providers->provider();
        if ($provider === null) {
            return $this->finish(['result' => 'idle'] + $report);
        }
        if (!$this->store->ready()) {
            return $this->finish(['result' => 'no_table'] + $report);
        }
        if ($this->state->paused() !== null) {
            return $this->finish(['result' => 'paused'] + $report);
        }
        if ($this->settings->killSwitch() !== null) {
            $this->counters->noteCapReached('kill_switch', 0);

            return $this->finish(['result' => 'kill_switch'] + $report);
        }
        if (!$this->lock->acquire()) {
            return ['result' => 'locked'] + $report;
        }
        try {
            $key = ModelKey::of($provider);
            $report['changed'] += $this->recheck($key);
            $report['changed'] += $this->markBadStored($key, $provider->dimensions());
            $report = $this->loop($provider, $key, $report);
        } finally {
            $this->lock->release();
        }
        if ($report['stored'] > 0 || $report['changed'] > 0) {
            $this->sidecar->queueRebuild();
        }

        return $this->finish($report);
    }

    /**
     * @param array{result: string, batches: int, sent: int, stored: int, errors: int, changed: int, at: int} $report
     * @return array{result: string, batches: int, sent: int, stored: int, errors: int, changed: int, at: int}
     */
    private function loop(EmbeddingProvider $provider, string $key, array $report): array
    {
        $started = $this->clock->monotonic();
        $maxBatches = max(1, $this->settings->int('drain_max_batches'));
        $size = max(1, min($this->settings->int('batch_size'), $provider->maxBatch()));
        $guard = $maxBatches * 4;
        while ($report['batches'] < $maxBatches && $guard-- > 0) {
            if ($this->clock->monotonic() - $started >= self::WALL_SECONDS) {
                $report['result'] = 'time_limit';
                break;
            }
            if ($this->settings->killSwitch() !== null) {
                $report['result'] = 'kill_switch';
                break;
            }
            $postsLeft = $this->counters->postsLeft();
            if ($postsLeft < 1) {
                $this->counters->noteCapReached('posts', $this->settings->int('daily_post_cap'));
                $report['result'] = 'cap_posts';
                break;
            }
            $rows = $this->store->duePending($key, $this->clock->now(), $size);
            if ($rows === []) {
                break;
            }
            [$items, $changed] = $this->prepare($rows, $provider);
            $report['changed'] += $changed;
            if ($items === []) {
                continue;
            }
            [$items, $trimmedBy] = $this->trim($items, $postsLeft, $provider);
            if ($items === []) {
                $this->noteTrim($trimmedBy);
                $report['result'] = 'cap_' . $trimmedBy;
                break;
            }
            $outcome = $this->embedder->run($provider, $items);
            $report['batches']++;
            $report['sent'] += $outcome->sent;
            $report['stored'] += $outcome->stored;
            $report['errors'] += $outcome->errors;
            if ($outcome->stop !== null) {
                $report['result'] = $outcome->stop;
                break;
            }
            if ($trimmedBy !== null) {
                $this->noteTrim($trimmedBy);
                $report['result'] = 'cap_' . $trimmedBy;
                break;
            }
        }

        return $report;
    }

    /**
     * Rebuilds each row's text. Rows whose post is gone, ineligible or empty
     * are settled here and not sent.
     *
     * @param list<IndexRow> $rows
     * @return array{0: list<Item>, 1: int}
     */
    private function prepare(array $rows, EmbeddingProvider $provider): array
    {
        $items = [];
        $changed = 0;
        $now = $this->clock->now();
        foreach ($rows as $row) {
            $post = get_post($row->postId);
            if (!$post instanceof WP_Post) {
                $this->store->deletePost($row->postId);
                $changed++;
                continue;
            }
            if (!$this->eligibility->isEligible($post)) {
                $row->status = IndexRow::INELIGIBLE;
                $row->updatedAt = $now;
                $this->store->save($row);
                $changed++;
                continue;
            }
            $text = $this->text->build($post);
            if (EmbeddingText::isEmpty($text, $this->settings->int('min_chars'))) {
                $row->status = IndexRow::EMPTY;
                $row->textHash = EmbeddingText::hash($text, $row->modelKey);
                $row->updatedAt = $now;
                $this->store->save($row);
                continue;
            }
            $row->textHash = EmbeddingText::hash($text, $row->modelKey);
            $items[] = new Item($row, $text);
        }

        return [$items, $changed];
    }

    /**
     * Trims a batch so it crosses neither daily limit.
     *
     * @param list<Item> $items
     * @return array{0: list<Item>, 1: ?string} items to send, and which limit trimmed them
     */
    private function trim(array $items, int $postsLeft, EmbeddingProvider $provider): array
    {
        $by = null;
        if (count($items) > $postsLeft) {
            $items = array_slice($items, 0, $postsLeft);
            $by = 'posts';
        }
        $tokensLeft = $this->counters->tokensLeft();
        if ($tokensLeft !== null) {
            $kept = [];
            $sum = 0;
            foreach ($items as $item) {
                $estimate = $this->tokens->estimate(EmbeddingText::truncate($item->text, $provider->maxInputChars()));
                if ($sum + $estimate > $tokensLeft) {
                    $by = 'tokens';
                    break;
                }
                $sum += $estimate;
                $kept[] = $item;
            }
            $items = $kept;
        }

        return [$items, $by];
    }

    private function noteTrim(?string $by): void
    {
        if ($by === 'posts') {
            $this->counters->noteCapReached('posts', $this->settings->int('daily_post_cap'));
        } elseif ($by === 'tokens') {
            $this->counters->noteCapReached('tokens', $this->settings->int('daily_token_cap'));
        }
    }

    /**
     * After a change of post types, walks one page of rows and marks those
     * whose posts are no longer eligible.
     */
    private function recheck(string $key): int
    {
        $cursor = $this->state->get('recheck_cursor');
        if (!is_int($cursor)) {
            return 0;
        }
        $rows = $this->store->page($key, $cursor, self::RECHECK_PAGE);
        $changed = 0;
        foreach ($rows as $row) {
            if ($row->status === IndexRow::INELIGIBLE) {
                continue;
            }
            $post = get_post($row->postId);
            if (!$post instanceof WP_Post || !$this->eligibility->isEligible($post)) {
                $row->status = IndexRow::INELIGIBLE;
                $row->updatedAt = $this->clock->now();
                $this->store->save($row);
                $changed++;
            }
        }
        if (count($rows) < self::RECHECK_PAGE) {
            $this->state->delete('recheck_cursor');
        } else {
            $this->state->set('recheck_cursor', end($rows)->postId);
        }

        return $changed;
    }

    /** Rows the scanners found undecodable become `error` with `bad_stored_vector`. */
    private function markBadStored(string $key, int $dim): int
    {
        $ids = $this->state->get('bad_stored', []);
        if (!is_array($ids) || $ids === []) {
            return 0;
        }
        $this->state->delete('bad_stored');
        $changed = 0;
        foreach ($ids as $id) {
            $row = $this->store->get((int) $id, $key);
            if ($row !== null && $row->status === IndexRow::OK && VectorMath::unpack($row->vector, $dim) === null) {
                $row->status = IndexRow::ERROR;
                $row->errorCode = 'bad_stored_vector';
                $row->updatedAt = $this->clock->now();
                $this->store->save($row);
                do_action('post_embeddings_failed', $row->postId, 'bad_stored_vector');
                $changed++;
            }
        }

        return $changed;
    }

    /**
     * @param array{result: string, batches: int, sent: int, stored: int, errors: int, changed: int, at: int} $report
     * @return array{result: string, batches: int, sent: int, stored: int, errors: int, changed: int, at: int}
     */
    private function finish(array $report): array
    {
        $this->state->set('last_drain', $report);
        if ($report['sent'] > 0 || $report['errors'] > 0) {
            $code = $report['result'] === 'ok' ? 'drain_ok' : 'drain_partial';
            $this->logger->info($code, $report);
        }

        return $report;
    }
}
