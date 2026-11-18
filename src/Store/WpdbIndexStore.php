<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Store;

/**
 * The index table through $wpdb.
 *
 * The vector is written as a binary string through `$wpdb->prepare()` with a
 * `%s` placeholder. That is safe: prepare escapes with the driver's
 * real-escape function, which handles every byte, and a table with a
 * LONGBLOB column is treated as binary by wpdb's charset checks, so the
 * query is not stripped of "invalid" text.
 */
final class WpdbIndexStore implements IndexStore
{
    private const COLUMNS = 'post_id, model_key, status, dim, vector, text_hash, tokens, attempts, error_code, next_attempt_at, embedded_at, updated_at';

    private ?bool $ready = null;

    public function __construct(private readonly \wpdb $db)
    {
    }

    public static function tableName(\wpdb $db): string
    {
        return $db->prefix . 'post_embeddings';
    }

    public function table(): string
    {
        return self::tableName($this->db);
    }

    public function ready(): bool
    {
        if ($this->ready === null) {
            $table = $this->table();
            $found = $this->db->get_var($this->db->prepare('SHOW TABLES LIKE %s', $this->db->esc_like($table)));
            $this->ready = $found === $table;
        }

        return $this->ready;
    }

    public function get(int $postId, string $modelKey): ?IndexRow
    {
        $row = $this->db->get_row(
            $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM %i WHERE post_id = %d AND model_key = %s', $this->table(), $postId, $modelKey),
            ARRAY_A
        );
        $this->check();

        return is_array($row) ? self::fromArray($row) : null;
    }

    public function getMany(array $postIds, string $modelKey): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $postIds), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return [];
        }
        // The id list holds integers only, so it is appended after prepare().
        $sql = $this->db->prepare('SELECT ' . self::COLUMNS . ' FROM %i WHERE model_key = %s', $this->table(), $modelKey)
            . ' AND post_id IN (' . implode(',', $ids) . ')';
        $out = [];
        foreach (self::list($this->db->get_results($sql, ARRAY_A)) as $r) {
            $out[$r->postId] = $r;
        }
        $this->check();

        return $out;
    }

    public function save(IndexRow $row): void
    {
        // One literal statement: '' and -1 stand for NULL. The vector uses
        // IF() with a flag, because comparing a binary blob with '' is not
        // reliable across collations.
        $sql = $this->db->prepare(
            'REPLACE INTO %i (' . self::COLUMNS . ") VALUES (%d, %s, %s, %d, IF(%d = 1, %s, NULL), %s, NULLIF(%d, -1), %d,
             NULLIF(%s, ''), NULLIF(%s, ''), NULLIF(%s, ''), %s)",
            $this->table(),
            $row->postId,
            $row->modelKey,
            $row->status,
            $row->dim,
            $row->vector === null ? 0 : 1,
            $row->vector ?? '',
            $row->textHash,
            $row->tokens ?? -1,
            $row->attempts,
            $row->errorCode ?? '',
            self::time($row->nextAttemptAt) ?? '',
            self::time($row->embeddedAt) ?? '',
            self::time($row->updatedAt) ?? gmdate('Y-m-d H:i:s')
        );
        if ($this->db->query((string) $sql) === false) {
            throw new StoreError('Could not write an index row: ' . $this->db->last_error);
        }
    }

    public function deletePost(int $postId): int
    {
        $n = $this->db->query((string) $this->db->prepare('DELETE FROM %i WHERE post_id = %d', $this->table(), $postId));
        $this->check();

        return is_int($n) ? $n : 0;
    }

    public function duePending(string $modelKey, int $now, int $limit): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                'SELECT ' . self::COLUMNS . " FROM %i
                 WHERE model_key = %s AND status = 'pending' AND (next_attempt_at IS NULL OR next_attempt_at <= %s)
                 ORDER BY updated_at ASC, post_id ASC LIMIT %d",
                $this->table(),
                $modelKey,
                gmdate('Y-m-d H:i:s', $now),
                max(1, $limit)
            ),
            ARRAY_A
        );
        $this->check();

        return self::list($rows);
    }

    public function page(string $modelKey, int $afterPostId, int $limit, ?string $status = null): array
    {
        if ($status === null) {
            $sql = $this->db->prepare(
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE model_key = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d',
                $this->table(),
                $modelKey,
                $afterPostId,
                max(1, $limit)
            );
        } else {
            $sql = $this->db->prepare(
                'SELECT ' . self::COLUMNS . ' FROM %i WHERE model_key = %s AND status = %s AND post_id > %d ORDER BY post_id ASC LIMIT %d',
                $this->table(),
                $modelKey,
                $status,
                $afterPostId,
                max(1, $limit)
            );
        }
        $rows = $this->db->get_results((string) $sql, ARRAY_A);
        $this->check();

        return self::list($rows);
    }

    public function counts(): array
    {
        $rows = $this->db->get_results(
            (string) $this->db->prepare('SELECT model_key, status, COUNT(*) AS n FROM %i GROUP BY model_key, status', $this->table()),
            ARRAY_A
        );
        $this->check();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['model_key'], $row['status'], $row['n'])) {
                $out[(string) $row['model_key']][(string) $row['status']] = (int) $row['n'];
            }
        }

        return $out;
    }

    public function retryErrors(string $modelKey): int
    {
        $n = $this->db->query((string) $this->db->prepare(
            "UPDATE %i SET status = 'pending', attempts = 0, error_code = NULL, next_attempt_at = NULL
             WHERE model_key = %s AND status = 'error'",
            $this->table(),
            $modelKey
        ));
        $this->check();

        return is_int($n) ? $n : 0;
    }

    public function pruneModel(string $modelKey): int
    {
        $n = $this->db->query((string) $this->db->prepare('DELETE FROM %i WHERE model_key = %s', $this->table(), $modelKey));
        $this->check();

        return is_int($n) ? $n : 0;
    }

    private function check(): void
    {
        if ($this->db->last_error !== '') {
            throw new StoreError('Index table query failed: ' . $this->db->last_error);
        }
    }

    private static function time(?int $ts): ?string
    {
        return $ts === null ? null : gmdate('Y-m-d H:i:s', $ts);
    }

    private static function fromTime(mixed $value): ?int
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000')) {
            return null;
        }
        $ts = strtotime($value . ' UTC');

        return $ts === false ? null : $ts;
    }

    /**
     * @param mixed $rows
     * @return list<IndexRow>
     */
    private static function list(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = self::fromArray($row);
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function fromArray(array $row): IndexRow
    {
        $status = (string) ($row['status'] ?? IndexRow::ERROR);
        if (!in_array($status, IndexRow::STATUSES, true)) {
            $status = IndexRow::ERROR;
        }

        return new IndexRow(
            (int) $row['post_id'],
            (string) $row['model_key'],
            $status,
            (string) ($row['text_hash'] ?? ''),
            (int) ($row['dim'] ?? 0),
            isset($row['vector']) ? (string) $row['vector'] : null,
            isset($row['tokens']) ? (int) $row['tokens'] : null,
            (int) ($row['attempts'] ?? 0),
            isset($row['error_code']) ? (string) $row['error_code'] : null,
            self::fromTime($row['next_attempt_at'] ?? null),
            self::fromTime($row['embedded_at'] ?? null),
            self::fromTime($row['updated_at'] ?? null) ?? 0
        );
    }
}
