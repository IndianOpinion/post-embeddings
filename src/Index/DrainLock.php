<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Index;

use IndianOpinion\PostEmbeddings\Clock;

/**
 * A lock in an option row written with add_option, holding an expiry ten
 * minutes ahead. add_option fails when the row exists, so only one drain
 * holds it. A lock past its expiry is taken over, and the taker re-reads the
 * row to confirm it won.
 */
final class DrainLock
{
    public const OPTION = 'post_embeddings_drain_lock';
    public const TTL = 600;

    private ?string $token = null;

    public function __construct(private readonly Clock $clock)
    {
    }

    public function acquire(): bool
    {
        $token = bin2hex(random_bytes(8));
        $value = ['token' => $token, 'expires' => $this->clock->now() + self::TTL];
        if (add_option(self::OPTION, $value, '', false)) {
            $this->token = $token;

            return true;
        }
        $current = get_option(self::OPTION);
        $expires = is_array($current) && isset($current['expires']) ? (int) $current['expires'] : 0;
        if ($expires > $this->clock->now()) {
            return false;
        }
        update_option(self::OPTION, $value, false);
        $check = get_option(self::OPTION);
        if (is_array($check) && ($check['token'] ?? null) === $token) {
            $this->token = $token;

            return true;
        }

        return false;
    }

    public function release(): void
    {
        if ($this->token === null) {
            return;
        }
        $current = get_option(self::OPTION);
        if (is_array($current) && ($current['token'] ?? null) === $this->token) {
            delete_option(self::OPTION);
        }
        $this->token = null;
    }
}
