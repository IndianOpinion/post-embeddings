<?php

/**
 * A wpdb over mysqli for the integration tests: the same prepare()
 * placeholders and the driver's real-escape function, against a real server.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

final class MysqliWpdb extends wpdb
{
    public function __construct(private mysqli $link)
    {
    }

    public function _real_escape(string $s): string
    {
        return $this->link->real_escape_string($s);
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        try {
            $result = $this->link->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();

            return false;
        }
        if ($result instanceof mysqli_result) {
            $n = $result->num_rows;
            $result->free();

            return (int) $n;
        }

        return (int) $this->link->affected_rows;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $this->queries[] = $sql;
        $this->last_error = '';
        try {
            $result = $this->link->query($sql);
        } catch (mysqli_sql_exception $e) {
            $this->last_error = $e->getMessage();

            return [];
        }
        if (!$result instanceof mysqli_result) {
            return [];
        }
        $rows = $result->fetch_all(MYSQLI_ASSOC);
        $result->free();

        return $rows;
    }

    public function get_var(string $sql): mixed
    {
        $rows = $this->rows($sql);

        return $rows === [] ? null : array_values($rows[0])[0];
    }

    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        return $this->rows($sql)[0] ?? null;
    }

    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        return $this->rows($sql);
    }

    public function get_col(string $sql): array
    {
        return array_map(static fn (array $r): mixed => array_values($r)[0], $this->rows($sql));
    }
}
