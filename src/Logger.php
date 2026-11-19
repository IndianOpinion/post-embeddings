<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings;

/**
 * Log events: always as the `post_embeddings_log` action, and to `error_log`
 * with the `[post-embeddings]` prefix when the `log` setting is on. Repeated
 * lines of one code are limited to one per 10 minutes. Context values are
 * scalars only: counts, ids, codes. Never text, queries or keys.
 */
final class Logger
{
    public const WINDOW = 600;

    /** @var callable(string): void */
    private $writer;

    /** @var array<string, int> */
    private array $lastWritten = [];

    /**
     * @param callable(string): void|null $writer
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly Clock $clock,
        ?callable $writer = null
    ) {
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $code, array $context = []): void
    {
        $context = self::clean($context);
        do_action('post_embeddings_log', $level, $code, $context);
        if (!$this->settings->bool('log') || !$this->claim($code)) {
            return;
        }
        $parts = ['[post-embeddings]', $level, $code];
        foreach ($context as $k => $v) {
            $parts[] = $k . '=' . (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
        }
        ($this->writer)(implode(' ', $parts));
    }

    /** @param array<string, mixed> $context */
    public function info(string $code, array $context = []): void
    {
        $this->log('info', $code, $context);
    }

    /** @param array<string, mixed> $context */
    public function warning(string $code, array $context = []): void
    {
        $this->log('warning', $code, $context);
    }

    /** @param array<string, mixed> $context */
    public function error(string $code, array $context = []): void
    {
        $this->log('error', $code, $context);
    }

    /**
     * Removes credentials from a provider body excerpt and cuts it to 300 bytes.
     */
    public static function redact(string $body, int $bytes = 300): string
    {
        // Redact before cutting, so a credential that straddles the cut is
        // still recognised whole.
        $body = substr($body, 0, max($bytes, 8192));
        $body = (string) preg_replace('/bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $body);
        $body = (string) preg_replace('/(authorization|api[-_]?key|x-api-key|token|secret)("?\s*[:=]\s*"?)[^",\s}]+/i', '$1$2[redacted]', $body);
        $body = (string) preg_replace('/\b(sk|pk|rk|key)[-_][A-Za-z0-9_-]{8,}/', '[redacted]', $body);
        $body = (string) preg_replace('/[A-Za-z0-9_-]{32,}/', '[redacted]', $body);
        $body = mb_scrub(substr($body, 0, $bytes), 'UTF-8');

        return (string) preg_replace('/[\x00-\x1f\x7f]+/', ' ', $body);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, scalar>
     */
    private static function clean(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            if (is_int($v) || is_float($v) || is_bool($v)) {
                $out[(string) $k] = $v;
            } elseif (is_string($v)) {
                $out[(string) $k] = mb_substr((string) preg_replace('/\s+/', ' ', $v), 0, 400);
            } elseif (is_array($v)) {
                $flat = [];
                foreach ($v as $item) {
                    if (is_scalar($item)) {
                        $flat[] = (string) $item;
                    }
                }
                $out[(string) $k] = implode(',', array_slice($flat, 0, 50));
            }
        }

        return $out;
    }

    private function claim(string $code): bool
    {
        $now = $this->clock->now();
        if (isset($this->lastWritten[$code]) && $now - $this->lastWritten[$code] < self::WINDOW) {
            return false;
        }
        $key = 'post_embeddings_log_' . substr(md5($code), 0, 16);
        $last = get_transient($key);
        if (is_int($last) && $now - $last < self::WINDOW) {
            $this->lastWritten[$code] = $last;

            return false;
        }
        set_transient($key, $now, self::WINDOW);
        $this->lastWritten[$code] = $now;

        return true;
    }
}
