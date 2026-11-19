<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/**
 * Optional integration with spend-meter (contract version 1), detected at
 * call time. The plugin asks `spend_meter_pre_spend` with an intent array
 * before every provider call, follows an allowed decision that carries a
 * `reservation_id` with exactly one `spend_meter_record` (the call was made)
 * or `spend_meter_release` (it was not), and records every attempt, billed
 * or not. A null decision means no meter: the plugin's own caps alone apply.
 * The plugin's caps and kill switch always apply, meter or not.
 */
final class SpendGate
{
    public const FILTER = 'spend_meter_pre_spend';
    public const RECORD = 'spend_meter_record';
    public const RELEASE = 'spend_meter_release';
    public const CALLER = 'post-embeddings';
    /** The spend-meter contract version this plugin speaks. */
    public const API = 1;

    /**
     * Asks the meter before a provider call.
     *
     * @param 'index'|'query' $purpose
     */
    public function reserve(string $purpose, string $provider, string $model, int $estimatedTokens): Reservation
    {
        $intent = [
            'purpose' => self::CALLER . '.' . $purpose,
            'caller' => self::CALLER,
            'provider' => strtolower($provider),
            'model' => $model,
            'est_input_tokens' => max(0, $estimatedTokens),
        ];
        $api = defined('SPEND_METER_API') ? (int) constant('SPEND_METER_API') : null;
        if ($api !== null && $api < self::API) {
            // An older contract than this plugin speaks: the meter's own
            // rule is to treat it as absent. Our own caps still apply.
            return Reservation::unmetered();
        }
        if ($api !== null && $api > self::API) {
            // A newer contract: refuse to spend rather than guess at its
            // shape. Under-counting is the dangerous direction.
            return Reservation::denied('spend_meter_api', $intent);
        }
        if (!defined('SPEND_METER_API') && !has_filter(self::FILTER)) {
            return Reservation::unmetered();
        }
        $decision = apply_filters(self::FILTER, null, $intent);
        if ($decision === null) {
            return Reservation::unmetered();
        }
        if (!is_array($decision) || ($decision['allowed'] ?? null) !== true) {
            $reason = is_array($decision) && isset($decision['reason']) && is_string($decision['reason'])
                ? $decision['reason'] : 'denied';

            return Reservation::denied($reason, $intent);
        }
        $id = $decision['reservation_id'] ?? null;

        return Reservation::allowed(is_string($id) && $id !== '' ? $id : null, $intent);
    }

    /**
     * Reports one attempt that was made. `$status` is `ok`, `error_billed`
     * or `error_unbilled`.
     *
     * @param 'ok'|'error_billed'|'error_unbilled' $status
     */
    public function record(Reservation $r, string $status, ?int $inputTokens, ?int $httpStatus = null, ?string $errorCode = null): void
    {
        if (!$r->metered || !$r->allowed) {
            return;
        }
        $call = [
            'reservation_id' => $r->id,
            'purpose' => (string) ($r->intent['purpose'] ?? ''),
            'caller' => self::CALLER,
            'provider' => (string) ($r->intent['provider'] ?? ''),
            'model' => (string) ($r->intent['model'] ?? ''),
            'status' => $status,
            'input_tokens' => max(0, (int) $inputTokens),
        ];
        if ($httpStatus !== null && $httpStatus > 0) {
            $call['http_status'] = $httpStatus;
        }
        if ($errorCode !== null && $errorCode !== '') {
            $call['error_code'] = substr($errorCode, 0, 64);
        }
        do_action(self::RECORD, $call);
    }

    /** Gives back a reservation for a call that was not made. */
    public function release(Reservation $r): void
    {
        if (!$r->metered || !$r->allowed || $r->id === null) {
            return;
        }
        do_action(self::RELEASE, $r->id);
    }
}
