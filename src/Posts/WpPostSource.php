<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Posts;

/**
 * PostSource over the posts table, keyset by ID, never OFFSET.
 */
final class WpPostSource implements PostSource
{
    public function __construct(private readonly \wpdb $db)
    {
    }

    public function ids(array $types, int $cursor, int $limit, string $order = 'asc', ?string $sinceGmt = null): array
    {
        if ($types === []) {
            return [];
        }
        $since = $sinceGmt ?? '1000-01-01 00:00:00';
        if ($order === 'desc') {
            $sql = $this->db->prepare(
                "SELECT ID FROM %i WHERE post_status = 'publish' AND post_password = '' AND ID < %d AND post_date_gmt >= %s",
                $this->db->posts,
                $cursor > 0 ? $cursor : PHP_INT_MAX,
                $since
            ) . $this->typeClause($types) . $this->db->prepare(' ORDER BY ID DESC LIMIT %d', max(1, $limit));
        } else {
            $sql = $this->db->prepare(
                "SELECT ID FROM %i WHERE post_status = 'publish' AND post_password = '' AND ID > %d AND post_date_gmt >= %s",
                $this->db->posts,
                $cursor,
                $since
            ) . $this->typeClause($types) . $this->db->prepare(' ORDER BY ID ASC LIMIT %d', max(1, $limit));
        }
        $ids = $this->db->get_col($sql);

        return array_values(array_map('intval', $ids));
    }

    public function count(array $types): int
    {
        if ($types === []) {
            return 0;
        }
        $sql = $this->db->prepare("SELECT COUNT(*) FROM %i WHERE post_status = 'publish' AND post_password = ''", $this->db->posts)
            . $this->typeClause($types);

        return (int) $this->db->get_var($sql);
    }

    /**
     * ` AND post_type IN (...)`, each type escaped through prepare().
     *
     * @param list<string> $types
     */
    private function typeClause(array $types): string
    {
        $quoted = array_map(fn (string $t): string => (string) $this->db->prepare('%s', $t), $types);

        return ' AND post_type IN (' . implode(',', $quoted) . ')';
    }
}
