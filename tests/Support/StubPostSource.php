<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Tests\Support;

use IndianOpinion\PostEmbeddings\Posts\PostSource;

final class StubPostSource implements PostSource
{
    public function ids(array $types, int $cursor, int $limit, string $order = 'asc', ?string $sinceGmt = null): array
    {
        $ids = [];
        foreach (\WpStub::$posts as $p) {
            if ($p->post_status !== 'publish' || $p->post_password !== '' || !in_array($p->post_type, $types, true)) {
                continue;
            }
            if ($sinceGmt !== null && $p->post_date_gmt < $sinceGmt) {
                continue;
            }
            if ($order === 'desc' ? ($cursor > 0 && $p->ID >= $cursor) : $p->ID <= $cursor) {
                continue;
            }
            $ids[] = $p->ID;
        }
        $order === 'desc' ? rsort($ids) : sort($ids);

        return array_slice($ids, 0, $limit);
    }

    public function count(array $types): int
    {
        return count($this->ids($types, 0, PHP_INT_MAX));
    }
}
