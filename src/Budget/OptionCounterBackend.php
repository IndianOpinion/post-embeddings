<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/**
 * Counters in the counters option. Read-modify-write, so not atomic across
 * processes: used by the tests and when no real database is present.
 */
final class OptionCounterBackend implements CounterBackend
{
    public function add(string $date, array $deltas): array
    {
        $raw = get_option(Counters::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        if (($raw['date'] ?? null) !== $date) {
            $raw = ['date' => $date, 'noted' => []];
        }
        foreach (self::NAMES as $name) {
            $raw[$name] = max(0, (int) ($raw[$name] ?? 0) + (int) ($deltas[$name] ?? 0));
        }
        update_option(Counters::OPTION, $raw, false);

        return $this->read($date);
    }

    public function read(string $date): array
    {
        $raw = get_option(Counters::OPTION, []);
        $raw = is_array($raw) && ($raw['date'] ?? null) === $date ? $raw : [];

        return [
            'index_posts' => (int) ($raw['index_posts'] ?? 0),
            'index_tokens' => (int) ($raw['index_tokens'] ?? 0),
            'queries' => (int) ($raw['queries'] ?? 0),
        ];
    }
}
