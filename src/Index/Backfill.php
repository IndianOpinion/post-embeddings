<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\ModelKey;
use IndianOpinion\PostEmbeddings\Posts\Eligibility;
use IndianOpinion\PostEmbeddings\Posts\PostSource;
use IndianOpinion\PostEmbeddings\Provider\ProviderResolver;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\Sidecar\Sidecar;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;
use WP_Post;

/**
 * Embeds the archive in capped, resumable runs (cases 63 to 65).
 */
final class Backfill
{
    public const SCAN_PAGE = 200;

    /** @var callable(string): void */
    private $out;

    public function __construct(
        private readonly IndexStore $store,
        private readonly PostSource $posts,
        private readonly ProviderResolver $providers,
        private readonly Eligibility $eligibility,
        private readonly EmbeddingText $text,
        private readonly TokenEstimator $tokens,
        private readonly Embedder $embedder,
        private readonly Counters $counters,
        private readonly Sidecar $sidecar,
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Clock $clock
    ) {
        $this->out = static function (string $line): void {
        };
    }

    /**
     * @param array{cap: int, types: list<string>, order: string, since: ?string, batch: int, dry_run: bool, resume: bool} $o
     * @param callable(string): void $out
     * @return int exit code
     */
    public function run(array $o, callable $out): int
    {
        $this->out = $out;
        $provider = $this->providers->provider();
        if ($provider === null) {
            $out('Error: no provider is configured.');

            return 1;
        }
        if (!$this->store->ready()) {
            $out('Error: the index table is missing. Deactivate and activate the plugin, or visit wp-admin once.');

            return 1;
        }
        $key = ModelKey::of($provider);
        $cursor = $this->startCursor($o);
        if ($o['dry_run']) {
            return $this->dryRun($o, $key, $cursor, $provider->maxInputChars());
        }
        $sent = 0;
        $storedTotal = 0;
        $batchNo = 0;
        $size = max(1, min($o['batch'], $provider->maxBatch()));
        $message = 'Done: nothing left to embed.';
        while (true) {
            if ($this->state->paused() !== null) {
                $message = 'Stopped: the plugin is paused after an authentication failure. Fix the key, then run `wp post-embeddings resume`.';
                break;
            }
            if ($this->settings->killSwitch() !== null) {
                $this->counters->noteCapReached('kill_switch', 0);
                $message = 'Stopped: the kill switch is on (' . $this->settings->killSwitch() . ').';
                break;
            }
            $capLeft = $o['cap'] - $sent;
            if ($capLeft < 1) {
                $message = sprintf('Stopped: reached --cap=%d.', $o['cap']);
                break;
            }
            $postsLeft = $this->counters->postsLeft();
            if ($postsLeft < 1) {
                $this->counters->noteCapReached('posts', $this->settings->int('daily_post_cap'));
                $message = 'Stopped: the daily post cap is reached. Run again after 00:00 UTC with --resume.';
                break;
            }
            $want = min($size, $capLeft, $postsLeft);
            [$items, $cursor, $exhausted, $changed] = $this->collect($o, $key, $cursor, $want);
            $tokensLeft = $this->counters->tokensLeft();
            if ($tokensLeft !== null) {
                $kept = [];
                $sum = 0;
                foreach ($items as $item) {
                    $estimate = $this->tokens->estimate(EmbeddingText::truncate($item->text, $provider->maxInputChars()));
                    if ($sum + $estimate > $tokensLeft) {
                        break;
                    }
                    $sum += $estimate;
                    $kept[] = $item;
                }
                if (count($kept) < count($items)) {
                    // Do not move the cursor past posts that were not sent.
                    $cursor = $this->cursorBefore($items[count($kept)]->row->postId, $o['order']);
                    $items = $kept;
                    if ($items === []) {
                        $this->saveCursor($o, $cursor);
                        $this->counters->noteCapReached('tokens', $this->settings->int('daily_token_cap'));
                        $message = 'Stopped: the daily token cap is reached. Run again after 00:00 UTC with --resume.';
                        break;
                    }
                }
            }
            if ($items === []) {
                $this->saveCursor($o, $cursor);
                if ($exhausted) {
                    break;
                }
                continue;
            }
            $outcome = $this->embedder->run($provider, $items);
            $batchNo++;
            $sent += $outcome->sent;
            $storedTotal += $outcome->stored;
            $this->saveCursor($o, $cursor);
            $out(sprintf(
                'Batch %d: sent %d, stored %d, errors %d, retry later %d, daily cap left %d, run cap left %d.',
                $batchNo,
                $outcome->sent,
                $outcome->stored,
                $outcome->errors,
                $outcome->retried,
                $this->counters->postsLeft(),
                max(0, $o['cap'] - $sent)
            ));
            if ($outcome->stop !== null) {
                $message = 'Stopped: ' . self::stopMessage($outcome->stop);
                break;
            }
            if ($exhausted) {
                break;
            }
        }
        if ($storedTotal > 0) {
            $this->sidecar->queueRebuild();
        }
        $out(sprintf('%s Sent %d, stored %d.', $message, $sent, $storedTotal));
        if ($message === 'Done: nothing left to embed.') {
            $this->state->delete('backfill');
        }

        return 0;
    }

    /**
     * Gathers up to $want posts that need embedding, from $cursor on.
     *
     * @param array{cap: int, types: list<string>, order: string, since: ?string, batch: int, dry_run: bool, resume: bool} $o
     * @return array{0: list<Item>, 1: int, 2: bool, 3: int} items, new cursor, whether the walk is finished, rows changed
     */
    private function collect(array $o, string $key, int $cursor, int $want): array
    {
        $items = [];
        $changed = 0;
        $minChars = $this->settings->int('min_chars');
        while (count($items) < $want) {
            $ids = $this->posts->ids($o['types'], $cursor, self::SCAN_PAGE, $o['order'], $o['since']);
            if ($ids === []) {
                return [$items, $cursor, true, $changed];
            }
            foreach ($ids as $id) {
                if (count($items) >= $want) {
                    return [$items, $cursor, false, $changed];
                }
                $cursor = $id;
                $post = get_post($id);
                if (!$post instanceof WP_Post || !$this->eligibility->isEligible($post)) {
                    continue;
                }
                $text = $this->text->build($post);
                $hash = EmbeddingText::hash($text, $key);
                $row = $this->store->get($id, $key);
                if ($row !== null && $row->status === IndexRow::OK && $row->textHash === $hash) {
                    continue;
                }
                if (EmbeddingText::isEmpty($text, $minChars)) {
                    if ($row === null || $row->status !== IndexRow::EMPTY || $row->textHash !== $hash) {
                        $row ??= new IndexRow($id, $key, IndexRow::EMPTY);
                        $row->status = IndexRow::EMPTY;
                        $row->textHash = $hash;
                        $row->updatedAt = $this->clock->now();
                        $this->store->save($row);
                        $changed++;
                    }
                    continue;
                }
                $row ??= new IndexRow($id, $key, IndexRow::PENDING);
                $row->status = IndexRow::PENDING;
                $row->textHash = $hash;
                $row->attempts = 0;
                $row->errorCode = null;
                $row->nextAttemptAt = null;
                $row->updatedAt = $this->clock->now();
                $items[] = new Item($row, $text);
            }
        }

        return [$items, $cursor, false, $changed];
    }

    /**
     * @param array{cap: int, types: list<string>, order: string, since: ?string, batch: int, dry_run: bool, resume: bool} $o
     */
    private function dryRun(array $o, string $key, int $cursor, int $limit): int
    {
        $count = 0;
        $tokens = 0;
        $postsLeft = $this->counters->postsLeft();
        $max = min($o['cap'], $postsLeft);
        $scanned = 0;
        while (true) {
            $ids = $this->posts->ids($o['types'], $cursor, self::SCAN_PAGE, $o['order'], $o['since']);
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $cursor = $id;
                $scanned++;
                $post = get_post($id);
                if (!$post instanceof WP_Post || !$this->eligibility->isEligible($post)) {
                    continue;
                }
                $text = $this->text->build($post);
                if (EmbeddingText::isEmpty($text, $this->settings->int('min_chars'))) {
                    continue;
                }
                $row = $this->store->get($id, $key);
                if ($row !== null && $row->status === IndexRow::OK && $row->textHash === EmbeddingText::hash($text, $key)) {
                    continue;
                }
                if ($count < $max) {
                    $tokens += $this->tokens->estimate(EmbeddingText::truncate($text, $limit));
                }
                $count++;
            }
        }
        $wouldSend = min($count, $max);
        ($this->out)(sprintf('Dry run: %d posts scanned, %d need embedding, %d would be sent this run.', $scanned, $count, $wouldSend));
        ($this->out)(sprintf('Estimated input tokens for those: %d.', $tokens));
        $tokensLeft = $this->counters->tokensLeft();
        ($this->out)(sprintf(
            "Today's indexing cap left: %d posts, %s tokens. Nothing was sent.",
            $postsLeft,
            $tokensLeft === null ? 'unlimited' : (string) $tokensLeft
        ));

        return 0;
    }

    /**
     * @param array{cap: int, types: list<string>, order: string, since: ?string, batch: int, dry_run: bool, resume: bool} $o
     */
    private function startCursor(array $o): int
    {
        if (!$o['resume']) {
            if (!$o['dry_run']) {
                $this->state->delete('backfill');
            }

            return 0;
        }
        $saved = $this->state->get('backfill');
        if (is_array($saved) && ($saved['signature'] ?? null) === self::signature($o) && is_int($saved['cursor'] ?? null)) {
            ($this->out)(sprintf('Resuming after post %d.', $saved['cursor']));

            return $saved['cursor'];
        }
        ($this->out)('No matching interrupted run; starting from the beginning.');

        return 0;
    }

    /**
     * @param array{cap: int, types: list<string>, order: string, since: ?string, batch: int, dry_run: bool, resume: bool} $o
     */
    private function saveCursor(array $o, int $cursor): void
    {
        $this->state->set('backfill', ['signature' => self::signature($o), 'cursor' => $cursor, 'at' => $this->clock->now()]);
    }

    private function cursorBefore(int $postId, string $order): int
    {
        return $order === 'desc' ? $postId + 1 : $postId - 1;
    }

    /**
     * @param array{cap: int, types: list<string>, order: string, since: ?string, batch: int, dry_run: bool, resume: bool} $o
     */
    private static function signature(array $o): string
    {
        return $o['order'] . '|' . implode(',', $o['types']) . '|' . ($o['since'] ?? '');
    }

    private static function stopMessage(string $stop): string
    {
        return match ($stop) {
            'auth' => 'the provider refused the credentials; the plugin is paused. Fix the key, then run `wp post-embeddings resume`.',
            'transient' => 'the provider is failing or busy; the batch will be retried by the drain. Try again later with --resume.',
            'spend_meter' => 'the spend meter denied the call.',
            'cap' => 'a daily cap was reached during retries.',
            default => $stop . '.',
        };
    }
}
