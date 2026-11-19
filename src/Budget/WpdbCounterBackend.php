<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Budget;

/**
 * Counters as one options row per counter per UTC date, changed with a
 * single `INSERT ... ON DUPLICATE KEY UPDATE` so concurrent processes never
 * lose an update. The rows are never read through the options API (they are
 * not autoloaded and never cached); rows of earlier dates are removed when a
 * new date's row is created.
 */
final class WpdbCounterBackend implements CounterBackend
{
    public const PREFIX = 'post_embeddings_cnt_';

    public function __construct(private readonly \wpdb $db)
    {
    }

    public static function optionName(string $name, string $date): string
    {
        return self::PREFIX . $name . '_' . str_replace('-', '', $date);
    }

    public function add(string $date, array $deltas): array
    {
        $created = false;
        foreach (self::NAMES as $name) {
            $delta = (int) ($deltas[$name] ?? 0);
            if ($delta === 0) {
                continue;
            }
            $sql = $this->db->prepare(
                "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no') "
                . 'ON DUPLICATE KEY UPDATE option_value = GREATEST(0, CAST(option_value AS SIGNED) + %d)',
                $this->db->options,
                self::optionName($name, $date),
                (string) max(0, $delta),
                $delta
            );
            if ($this->db->query((string) $sql) === 1) {
                $created = true;
            }
        }
        if ($created) {
            $this->db->query((string) $this->db->prepare(
                'DELETE FROM %i WHERE option_name LIKE %s AND option_name NOT LIKE %s',
                $this->db->options,
                $this->db->esc_like(self::PREFIX) . '%',
                '%' . $this->db->esc_like('_' . str_replace('-', '', $date))
            ));
        }

        return $this->read($date);
    }

    public function read(string $date): array
    {
        $out = ['index_posts' => 0, 'index_tokens' => 0, 'queries' => 0];
        $names = [];
        foreach (self::NAMES as $name) {
            $names[self::optionName($name, $date)] = $name;
        }
        $sql = $this->db->prepare(
            'SELECT option_name, option_value FROM %i WHERE option_name IN (%s, %s, %s)',
            $this->db->options,
            ...array_keys($names)
        );
        $rows = $this->db->get_results((string) $sql, ARRAY_A);
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['option_name'], $names[$row['option_name']])) {
                $out[$names[$row['option_name']]] = max(0, (int) $row['option_value']);
            }
        }

        return $out;
    }
}
