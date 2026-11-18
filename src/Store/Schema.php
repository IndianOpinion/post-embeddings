<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Store;

/**
 * Creates and upgrades the index table with dbDelta, then verifies every
 * column with SHOW COLUMNS, because dbDelta can skip a column add without
 * an error.
 */
final class Schema
{
    public const VERSION = '1';
    public const VERSION_OPTION = 'post_embeddings_db_version';
    public const MISSING_OPTION = 'post_embeddings_schema_missing';

    /** Column => expected type prefix (lower case). */
    public const COLUMNS = [
        'post_id' => 'bigint',
        'model_key' => 'varchar',
        'status' => 'varchar',
        'dim' => 'smallint',
        'vector' => 'longblob',
        'text_hash' => 'char',
        'tokens' => 'int',
        'attempts' => 'tinyint',
        'error_code' => 'varchar',
        'next_attempt_at' => 'datetime',
        'embedded_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function __construct(private readonly \wpdb $db)
    {
    }

    public function createSql(): string
    {
        $table = WpdbIndexStore::tableName($this->db);
        $collate = $this->db->get_charset_collate();

        return "CREATE TABLE {$table} (
  post_id bigint(20) unsigned NOT NULL,
  model_key varchar(191) NOT NULL,
  status varchar(16) NOT NULL DEFAULT 'pending',
  dim smallint(5) unsigned NOT NULL DEFAULT 0,
  vector longblob NULL,
  text_hash char(64) NOT NULL DEFAULT '',
  tokens int(10) unsigned NULL,
  attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
  error_code varchar(64) NULL,
  next_attempt_at datetime NULL,
  embedded_at datetime NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (post_id,model_key),
  KEY model_status_post (model_key,status,post_id),
  KEY status_next (status,next_attempt_at)
) {$collate};";
    }

    /**
     * Runs dbDelta and verifies. Returns the missing or wrongly typed columns.
     *
     * @return list<string>
     */
    public function install(): array
    {
        if (!function_exists('dbDelta') && defined('ABSPATH')) {
            self::loadUpgrade(ABSPATH . 'wp-admin/includes/upgrade.php');
        }
        if (function_exists('dbDelta')) {
            dbDelta($this->createSql());
        }
        $missing = $this->verify();
        if ($missing === []) {
            update_option(self::VERSION_OPTION, self::VERSION, false);
            delete_option(self::MISSING_OPTION);
        } else {
            update_option(self::MISSING_OPTION, $missing, false);
        }

        return $missing;
    }

    private static function loadUpgrade(string $file): void
    {
        if (is_file($file)) {
            require_once $file;
        }
    }

    /** Installs when the stored version is missing or old. */
    public function maybeUpgrade(): void
    {
        if (get_option(self::VERSION_OPTION) !== self::VERSION) {
            $this->install();
        }
    }

    /**
     * Columns that SHOW COLUMNS does not report with the expected type.
     *
     * @return list<string>
     */
    public function verify(): array
    {
        $table = WpdbIndexStore::tableName($this->db);
        $rows = $this->db->get_results((string) $this->db->prepare('SHOW COLUMNS FROM %i', $table), ARRAY_A);
        $found = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && isset($row['Field'], $row['Type'])) {
                $found[strtolower((string) $row['Field'])] = strtolower((string) $row['Type']);
            }
        }
        $missing = [];
        foreach (self::COLUMNS as $column => $type) {
            if (!isset($found[$column]) || !str_starts_with($found[$column], $type)) {
                $missing[] = $column;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public static function missingColumns(): array
    {
        $missing = get_option(self::MISSING_OPTION, []);

        return is_array($missing) ? array_values(array_map('strval', $missing)) : [];
    }

    public function drop(): void
    {
        $this->db->query((string) $this->db->prepare('DROP TABLE IF EXISTS %i', WpdbIndexStore::tableName($this->db)));
    }
}
