<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

use IndianOpinion\PostEmbeddings\Budget\Counters;
use IndianOpinion\PostEmbeddings\Budget\SpendGate;
use IndianOpinion\PostEmbeddings\Clock;
use IndianOpinion\PostEmbeddings\Logger;
use IndianOpinion\PostEmbeddings\Provider\AuthProviderError;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider;
use IndianOpinion\PostEmbeddings\Provider\EmbeddingResult;
use IndianOpinion\PostEmbeddings\Provider\InputTooLongError;
use IndianOpinion\PostEmbeddings\Provider\MalformedResponseError;
use IndianOpinion\PostEmbeddings\Provider\RejectedInputError;
use IndianOpinion\PostEmbeddings\Provider\TransientProviderError;
use IndianOpinion\PostEmbeddings\Settings;
use IndianOpinion\PostEmbeddings\State;
use IndianOpinion\PostEmbeddings\Store\IndexRow;
use IndianOpinion\PostEmbeddings\Store\IndexStore;
use IndianOpinion\PostEmbeddings\Text\EmbeddingText;
use IndianOpinion\PostEmbeddings\Text\TokenEstimator;
use IndianOpinion\PostEmbeddings\Vector\VectorMath;

/**
 * Sends one batch to the provider and records the outcome on each row:
 * validation (case 16), storage (17), mapping (18), and every failure class
 * (20 to 25). Shared by the drain and the backfill.
 */
final class Embedder
{
    /** Backoff in seconds after the 1st, 2nd, 3rd, 4th and later failures. */
    public const BACKOFF = [300, 900, 3600, 14400, 43200];

    public function __construct(
        private readonly IndexStore $store,
        private readonly Counters $counters,
        private readonly SpendGate $meter,
        private readonly TokenEstimator $tokens,
        private readonly Settings $settings,
        private readonly State $state,
        private readonly Clock $clock,
        private readonly Logger $logger
    ) {
    }

    /**
     * @param list<Item> $items
     */
    public function run(EmbeddingProvider $provider, array $items): BatchOutcome
    {
        $out = new BatchOutcome();
        if ($items === []) {
            return $out;
        }
        $limit = $provider->maxInputChars();
        $texts = array_map(static fn (Item $i): string => EmbeddingText::truncate($i->text, $limit), $items);
        try {
            $result = $this->call($provider, $texts, $out);
            if ($result === null) {
                return $out;
            }
        } catch (InputTooLongError) {
            $half = max(1, intdiv($limit, 2));
            foreach ($items as $item) {
                if ($out->stop !== null) {
                    break;
                }
                $this->single($provider, $item, $half, false, $out);
            }

            return $out;
        } catch (RejectedInputError $e) {
            if (count($items) === 1) {
                $this->fail($items[0], 'rejected_' . $e->httpStatus, $out);

                return $out;
            }
            foreach ($items as $item) {
                if ($out->stop !== null) {
                    break;
                }
                $this->single($provider, $item, $limit, true, $out);
            }

            return $out;
        } catch (AuthProviderError) {
            $this->pauseForAuth();
            $out->stop = 'auth';

            return $out;
        } catch (TransientProviderError $e) {
            $this->transient($e, $items, $out);

            return $out;
        }
        $this->apply($provider, $result, $items, $out);

        return $out;
    }

    /**
     * One post on its own, after a batch failed in a way that needs the bad
     * input found or the limit halved.
     */
    private function single(EmbeddingProvider $provider, Item $item, int $limit, bool $mayHalve, BatchOutcome $out): void
    {
        $text = EmbeddingText::truncate($item->text, $limit);
        $tokensLeft = $this->counters->tokensLeft();
        if ($this->counters->postsLeft() < 1 || ($tokensLeft !== null && $this->tokens->estimate($text) > $tokensLeft)) {
            $out->stop = 'cap';

            return;
        }
        try {
            $result = $this->call($provider, [$text], $out);
            if ($result === null) {
                return;
            }
        } catch (InputTooLongError) {
            if ($mayHalve) {
                $this->single($provider, $item, max(1, intdiv($limit, 2)), false, $out);
            } else {
                $this->fail($item, 'too_long', $out);
            }

            return;
        } catch (RejectedInputError $e) {
            $this->fail($item, 'rejected_' . $e->httpStatus, $out);

            return;
        } catch (AuthProviderError) {
            $this->pauseForAuth();
            $out->stop = 'auth';

            return;
        } catch (TransientProviderError $e) {
            $this->transient($e, [$item], $out);

            return;
        }
        $this->apply($provider, $result, [$item], $out);
    }

    /**
     * Asks the meter, makes the call, and counts it. Returns null when the
     * meter says no (the caller stops). A call that may have been billed is
     * counted even when it fails.
     *
     * @param list<string> $texts
     */
    private function call(EmbeddingProvider $provider, array $texts, BatchOutcome $out): ?EmbeddingResult
    {
        $estimate = $this->tokens->estimateAll($texts);
        $reservation = $this->meter->reserve('index', $provider->id(), $provider->model(), $estimate);
        if (!$reservation->allowed) {
            $this->counters->noteCapReached('spend_meter', 0);
            $out->stop = 'spend_meter';

            return null;
        }
        $tokensLeft = $this->counters->tokensLeft();
        if ($this->counters->postsLeft() < count($texts) || ($tokensLeft !== null && $tokensLeft < $estimate)) {
            // The budget moved while the meter was asked (another process
            // spent it): give the reservation back and stop.
            $this->meter->release($reservation);
            $out->stop = 'cap';

            return null;
        }
        $out->calls++;
        try {
            $result = $provider->embed($texts);
        } catch (TransientProviderError | AuthProviderError | InputTooLongError | RejectedInputError $e) {
            $billed = $e instanceof TransientProviderError && $e->maybeBilled;
            if ($billed) {
                $this->counters->addIndex(count($texts), $estimate);
                $out->sent += count($texts);
            }
            $this->meter->record($reservation, 'error', $billed ? $estimate : 0);

            throw $e;
        } catch (\Throwable $e) {
            // An adapter that broke its contract: count it as spent, retry later.
            $this->counters->addIndex(count($texts), $estimate);
            $out->sent += count($texts);
            $this->meter->record($reservation, 'error', $estimate);
            $this->logger->error('provider_contract', ['class' => get_class($e)]);

            throw new TransientProviderError('Adapter threw ' . get_class($e), null, true);
        }
        $used = $result->inputTokens ?? $estimate;
        $this->counters->addIndex(count($texts), $used);
        $this->meter->record($reservation, 'ok', $used);
        $out->sent += count($texts);
        $out->tokens += $used;

        return $result;
    }

    /**
     * @param list<Item> $items
     */
    private function apply(EmbeddingProvider $provider, EmbeddingResult $result, array $items, BatchOutcome $out): void
    {
        $dim = $provider->dimensions();
        $now = $this->clock->now();
        foreach ($items as $i => $item) {
            if (!$result->hasVector($i)) {
                $code = $result->error($i);
                if ($code !== null) {
                    $this->fail($item, 'rejected_' . preg_replace('/[^a-z0-9_]/', '', strtolower($code)), $out);
                } else {
                    $this->retryLater($item, null, 'missing_in_response', $out);
                }
                continue;
            }
            $vector = $result->vector($i);
            $problem = VectorMath::problem($vector, $dim);
            if ($problem !== null) {
                if ($problem === 'length') {
                    $this->logger->warning('bad_vector_length', [
                        'post' => $item->row->postId,
                        'expected' => $dim,
                        'given' => is_array($vector) ? count($vector) : 0,
                    ]);
                }
                $this->fail($item, 'bad_vector_' . $problem, $out);
                continue;
            }
            /** @var list<int|float> $vector */
            $row = $item->row;
            $row->status = IndexRow::OK;
            $row->vector = VectorMath::pack(VectorMath::normalise($vector));
            $row->dim = $dim;
            $row->tokens = count($items) === 1 ? $result->inputTokens : null;
            $row->attempts = 0;
            $row->errorCode = null;
            $row->nextAttemptAt = null;
            $row->embeddedAt = $now;
            $row->updatedAt = $now;
            $this->store->save($row);
            $out->stored++;
            do_action('post_embeddings_stored', $row->postId, $row->modelKey);
        }
    }

    /**
     * @param list<Item> $items
     */
    private function transient(TransientProviderError $e, array $items, BatchOutcome $out): void
    {
        if ($e instanceof MalformedResponseError) {
            $this->logger->warning('provider_malformed', ['status' => $e->httpStatus, 'body' => $e->excerpt]);
        } else {
            $this->logger->warning('provider_transient', [
                'status' => $e->httpStatus,
                'retry_after' => $e->retryAfter ?? 0,
                'posts' => count($items),
            ]);
        }
        foreach ($items as $item) {
            $this->retryLater($item, $e->retryAfter, 'provider_transient', $out);
        }
        $out->stop = 'transient';
    }

    private function retryLater(Item $item, ?int $retryAfter, string $code, BatchOutcome $out): void
    {
        $row = $item->row;
        $row->attempts++;
        $now = $this->clock->now();
        $row->updatedAt = $now;
        if ($row->attempts >= max(1, $this->settings->int('max_attempts'))) {
            $this->fail($item, 'gave_up', $out);

            return;
        }
        $delay = self::BACKOFF[min($row->attempts - 1, count(self::BACKOFF) - 1)];
        if ($retryAfter !== null && $retryAfter > $delay) {
            $delay = $retryAfter;
        }
        $row->status = IndexRow::PENDING;
        $row->errorCode = $code;
        $row->nextAttemptAt = $now + $delay;
        $this->store->save($row);
        $out->retried++;
    }

    private function fail(Item $item, string $code, BatchOutcome $out): void
    {
        $row = $item->row;
        $row->status = IndexRow::ERROR;
        $row->errorCode = substr($code, 0, 64);
        $row->nextAttemptAt = null;
        $row->updatedAt = $this->clock->now();
        $this->store->save($row);
        $out->errors++;
        do_action('post_embeddings_failed', $row->postId, $row->errorCode);
    }

    private function pauseForAuth(): void
    {
        if ($this->state->paused() === null) {
            $this->state->pause('paused_auth');
            $this->logger->error('provider_auth', []);
            do_action('post_embeddings_paused', 'auth');
        }
    }
}
