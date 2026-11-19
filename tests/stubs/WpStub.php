<?php

/**
 * State behind the WordPress function stubs. Reset before every test.
 */

declare(strict_types=1);

final class WpStub
{
    /** @var array<string, mixed> */
    public static array $options = [];
    /** @var array<string, mixed> */
    public static array $transients = [];
    /** @var array<string, mixed> */
    public static array $cache = [];
    public static bool $extCache = false;
    /** @var array<string, array<int, list<callable>>> */
    public static array $hooks = [];
    /** @var list<array{0: string, 1: list<mixed>}> */
    public static array $actions = [];
    /** @var array<int, WP_Post> */
    public static array $posts = [];
    public static int $nextId = 1;
    /** @var list<array{hook: string, time: int, recurrence: ?string}> */
    public static array $events = [];
    /** @var callable|null */
    public static $http = null;
    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $requests = [];
    /** @var array<string, bool> */
    public static array $caps = ['manage_options' => true];
    public static bool $singular = false;
    public static int $queried = 0;
    /** @var array<int, int> */
    public static array $thumbs = [];
    /** @var array<int, array<string, mixed>> */
    public static array $meta = [];
    /** @var array<int, list<int>> */
    public static array $cats = [];
    /** @var list<array<string, mixed>> */
    public static array $queries = [];
    public static ?Throwable $queryThrows = null;
    /** @var array<string, mixed> */
    public static array $routes = [];
    /** @var array<string, callable> */
    public static array $shortcodes = [];
    /** @var list<string> */
    public static array $blocks = [];
    /** @var array<string, mixed> */
    public static array $cli = [];
    /** @var list<string> */
    public static array $privacy = [];
    public static bool $multisite = false;
    public static int $blog = 1;
    /** @var list<int> */
    public static array $blogStack = [];
    /** @var list<int> */
    public static array $sites = [1];
    /** @var list<string> */
    public static array $redirects = [];
    /** @var list<string> */
    public static array $dbDelta = [];
    /** @var callable|null */
    public static $dbDeltaCallback = null;
    /** @var list<string> */
    public static array $extraTypes = [];

    public static function reset(): void
    {
        self::$options = [];
        self::$transients = [];
        self::$cache = [];
        self::$extCache = false;
        self::$hooks = [];
        self::$actions = [];
        self::$posts = [];
        self::$nextId = 1;
        self::$events = [];
        self::$http = null;
        self::$requests = [];
        self::$caps = ['manage_options' => true];
        self::$singular = false;
        self::$queried = 0;
        self::$thumbs = [];
        self::$meta = [];
        self::$cats = [];
        self::$queries = [];
        self::$queryThrows = null;
        self::$routes = [];
        self::$shortcodes = [];
        self::$blocks = [];
        self::$cli = [];
        self::$privacy = [];
        self::$multisite = false;
        self::$blog = 1;
        self::$blogStack = [];
        self::$sites = [1];
        self::$redirects = [];
        self::$dbDelta = [];
        self::$dbDeltaCallback = null;
        self::$extraTypes = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        unset($_SERVER['HTTP_X_FORWARDED_FOR']);
        global $wpdb;
        if ($wpdb instanceof wpdb) {
            $wpdb->prefix = 'wp_';
        }
    }

    /** @param array<string, mixed> $props */
    public static function post(array $props = []): WP_Post
    {
        $id = (int) ($props['ID'] ?? self::$nextId);
        self::$nextId = max(self::$nextId, $id + 1);
        $props['ID'] = $id;
        $props += ['post_title' => 'Post ' . $id, 'post_content' => str_repeat('Words about subject ' . $id . '. ', 5)];
        $post = new WP_Post($props);
        self::$posts[$id] = $post;

        return $post;
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    public static function fired(string $tag): array
    {
        return array_values(array_filter(self::$actions, static fn (array $a): bool => $a[0] === $tag));
    }

    /**
     * @param array<string, mixed> $args
     * @return list<WP_Post>
     */
    public static function runQuery(array $args): array
    {
        $posts = array_values(self::$posts);
        $types = $args['post_type'] ?? null;
        $types = $types === null ? null : (array) $types;
        $status = $args['post_status'] ?? 'publish';
        $statuses = (array) $status;
        $in = isset($args['post__in']) ? array_map('intval', (array) $args['post__in']) : null;
        $notIn = array_map('intval', (array) ($args['post__not_in'] ?? []));
        $cats = isset($args['category__in']) ? array_map('intval', (array) $args['category__in']) : null;
        $s = isset($args['s']) ? mb_strtolower((string) $args['s']) : null;
        $out = [];
        foreach ($posts as $p) {
            if ($types !== null && !in_array($p->post_type, $types, true)) {
                continue;
            }
            if (!in_array($p->post_status, $statuses, true)) {
                continue;
            }
            if (($args['has_password'] ?? null) === false && $p->post_password !== '') {
                continue;
            }
            if ($in !== null && !in_array($p->ID, $in, true)) {
                continue;
            }
            if (in_array($p->ID, $notIn, true)) {
                continue;
            }
            if ($cats !== null && array_intersect($cats, self::$cats[$p->ID] ?? []) === []) {
                continue;
            }
            if ($s !== null) {
                $hay = mb_strtolower($p->post_title . ' ' . $p->post_content);
                $words = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $all = true;
                foreach ($words as $w) {
                    if (!str_contains($hay, $w)) {
                        $all = false;
                    }
                }
                if (!$all) {
                    continue;
                }
            }
            $out[] = $p;
        }
        $orderby = $args['orderby'] ?? 'date';
        if ($orderby === 'post__in' && $in !== null) {
            usort($out, static fn (WP_Post $a, WP_Post $b): int => array_search($a->ID, $in, true) <=> array_search($b->ID, $in, true));
        } else {
            usort($out, static fn (WP_Post $a, WP_Post $b): int => [$b->post_date_gmt, $b->ID] <=> [$a->post_date_gmt, $a->ID]);
        }
        $n = (int) ($args['posts_per_page'] ?? 10);

        return $n < 0 ? $out : array_slice($out, 0, $n);
    }
}
