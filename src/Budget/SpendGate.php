<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/**
 * Optional integration with a spend meter, detected at call time through
 * its filter. When no callback is hooked, the plugin's own caps alone apply.
 * The plugin's caps and kill switch always apply, meter or not.
 */
final class SpendGate
{
    public const FILTER = 'spend_meter_pre_spend';

    /**
     * Asks the meter before a provider call.
     *
     * @param 'index'|'query' $purpose
     */
    public function reserve(string $purpose, string $provider, string $model, int $estimatedTokens): Reservation
    {
        if (!has_filter(self::FILTER)) {
            return Reservation::unmetered();
        }
        $decision = apply_filters(self::FILTER, null, [
            'purpose' => 'post-embeddings.' . $purpose,
            'caller' => 'post-embeddings',
            'provider' => $provider,
            'model' => $model,
            'estimated_input_tokens' => $estimatedTokens,
        ]);
        if ($decision === null) {
            return Reservation::unmetered();
        }
        if (!is_array($decision) || ($decision['allowed'] ?? null) !== true) {
            $reason = is_array($decision) && isset($decision['reason']) && is_string($decision['reason'])
                ? $decision['reason'] : 'denied';

            return Reservation::denied($reason);
        }
        $id = $decision['reservation_id'] ?? ($decision['id'] ?? null);

        return Reservation::allowed(is_scalar($id) ? (string) $id : null);
    }

    /**
     * Reports the outcome of a call that was made.
     */
    public function record(Reservation $r, string $status, ?int $inputTokens): void
    {
        if (!$r->metered) {
            return;
        }
        do_action('spend_meter_record', $r->id, [
            'status' => $status,
            'input_tokens' => $inputTokens,
            'caller' => 'post-embeddings',
        ]);
    }

    /** Gives back a reservation for a call that was not made. */
    public function release(Reservation $r): void
    {
        if (!$r->metered) {
            return;
        }
        do_action('spend_meter_release', $r->id);
    }
}
