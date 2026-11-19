<?php

/**
 * WordPress function stubs backed by WpStub.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects, Squiz.Functions.GlobalFunction

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}
if (!defined('OBJECT')) {
    define('OBJECT', 'OBJECT');
}

function add_filter(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    WpStub::$hooks[$tag][$priority][] = $cb;

    return true;
}

function add_action(string $tag, callable $cb, int $priority = 10, int $args = 1): bool
{
    return add_filter($tag, $cb, $priority, $args);
}

function remove_all_filters(string $tag): void
{
    unset(WpStub::$hooks[$tag]);
}

function has_filter(string $tag): bool
{
    return !empty(WpStub::$hooks[$tag]);
}

function apply_filters(string $tag, mixed $value, mixed ...$args): mixed
{
    if (empty(WpStub::$hooks[$tag])) {
        return $value;
    }
    ksort(WpStub::$hooks[$tag]);
    foreach (WpStub::$hooks[$tag] as $callbacks) {
        foreach ($callbacks as $cb) {
            $value = $cb($value, ...$args);
        }
    }

    return $value;
}

function do_action(string $tag, mixed ...$args): void
{
    WpStub::$actions[] = [$tag, $args];
    if (empty(WpStub::$hooks[$tag])) {
        return;
    }
    ksort(WpStub::$hooks[$tag]);
    foreach (WpStub::$hooks[$tag] as $callbacks) {
        foreach ($callbacks as $cb) {
            $cb(...$args);
        }
    }
}

function __return_true(): bool
{
    return true;
}

function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, WpStub::$options) ? WpStub::$options[$name] : $default;
}

function update_option(string $name, mixed $value, mixed $autoload = null): bool
{
    WpStub::$options[$name] = $value;

    return true;
}

function add_option(string $name, mixed $value = '', string $deprecated = '', mixed $autoload = true): bool
{
    if (array_key_exists($name, WpStub::$options)) {
        return false;
    }
    WpStub::$options[$name] = $value;

    return true;
}

function delete_option(string $name): bool
{
    unset(WpStub::$options[$name]);

    return true;
}

function get_transient(string $name): mixed
{
    return WpStub::$transients[$name] ?? false;
}

function set_transient(string $name, mixed $value, int $ttl = 0): bool
{
    WpStub::$transients[$name] = $value;

    return true;
}

function delete_transient(string $name): bool
{
    unset(WpStub::$transients[$name]);

    return true;
}

function wp_using_ext_object_cache(): bool
{
    return WpStub::$extCache;
}

function wp_cache_get(string $key, string $group = '', bool $force = false, ?bool &$found = null): mixed
{
    $k = $group . ':' . $key;
    $found = array_key_exists($k, WpStub::$cache);

    return $found ? WpStub::$cache[$k] : false;
}

function wp_cache_set(string $key, mixed $value, string $group = '', int $ttl = 0): bool
{
    WpStub::$cache[$group . ':' . $key] = $value;

    return true;
}

function wp_cache_delete(string $key, string $group = ''): bool
{
    unset(WpStub::$cache[$group . ':' . $key]);

    return true;
}

function get_post(mixed $post = null): ?WP_Post
{
    if ($post instanceof WP_Post) {
        return $post;
    }

    return WpStub::$posts[(int) $post] ?? null;
}

function get_post_type(mixed $post = null): string|false
{
    $p = get_post($post);

    return $p === null ? false : $p->post_type;
}

/** @return array<string, string> */
function get_post_types(array $args = [], string $output = 'names'): array
{
    $types = array_merge(['post', 'page'], WpStub::$extraTypes);

    return array_combine($types, $types);
}

function is_post_type_viewable(mixed $type): bool
{
    return in_array($type, get_post_types(), true);
}

function wp_is_post_autosave(mixed $post): int|false
{
    $p = get_post($post);

    return $p !== null && $p->post_type === 'revision' && str_contains($p->post_name, 'autosave') ? $p->post_parent : false;
}

function wp_is_post_revision(mixed $post): int|false
{
    $p = get_post($post);

    return $p !== null && $p->post_type === 'revision' ? $p->post_parent : false;
}

function strip_shortcodes(string $content): string
{
    return (string) preg_replace('/\[\/?(gallery|caption|embed|audio|video|playlist|contact)\b[^\]]*\]/i', '', $content);
}

function wp_strip_all_tags(string $text): string
{
    return trim(strip_tags($text));
}

function esc_html(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(mixed $text): string
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_url(mixed $url): string
{
    $url = (string) $url;
    if ($url !== '' && preg_match('#^(https?:)?//|^/|^\?#i', $url) !== 1) {
        return '';
    }

    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function sanitize_key(string $key): string
{
    return (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
}

function wp_unslash(mixed $value): mixed
{
    return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value);
}

function wp_json_encode(mixed $data): string|false
{
    return json_encode($data);
}

function home_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function site_url(string $path = ''): string
{
    return 'https://example.test' . $path;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . $path;
}

function content_url(string $path = ''): string
{
    return 'https://example.test/wp-content' . $path;
}

function plugins_url(string $path = '', string $file = ''): string
{
    return 'https://example.test/wp-content/plugins/post-embeddings/' . $path;
}

function add_query_arg(mixed ...$args): string
{
    if (is_array($args[0])) {
        $params = $args[0];
        $url = (string) ($args[1] ?? '');
    } else {
        $params = [(string) $args[0] => $args[1]];
        $url = (string) ($args[2] ?? '');
    }
    $sep = str_contains($url, '?') ? '&' : '?';
    $parts = [];
    foreach ($params as $k => $v) {
        $parts[] = $k . '=' . $v;
    }

    return $url . $sep . implode('&', $parts);
}

function get_permalink(mixed $post = 0): string|false
{
    $p = get_post($post);

    return $p === null ? false : 'https://example.test/post-' . $p->ID . '/';
}

function get_post_time(string $format, bool $gmt, mixed $post): string
{
    $p = get_post($post);

    return $p === null ? '' : gmdate($format, (int) strtotime($p->post_date_gmt . ' UTC'));
}

function get_the_date(string $format = '', mixed $post = null): string
{
    $p = get_post($post);

    return $p === null ? '' : gmdate('F j, Y', (int) strtotime($p->post_date));
}

function get_post_thumbnail_id(mixed $post): int
{
    $p = get_post($post);

    return $p === null ? 0 : (WpStub::$thumbs[$p->ID] ?? 0);
}

/** @return array{0: string, 1: int, 2: int, 3: bool}|false */
function wp_get_attachment_image_src(int $id, string $size = 'thumbnail'): array|false
{
    return $id > 0 ? ['https://example.test/wp-content/uploads/img-' . $id . '-150x150.jpg', 150, 150, true] : false;
}

function get_post_meta(int $id, string $key = '', bool $single = false): mixed
{
    return WpStub::$meta[$id][$key] ?? ($single ? '' : []);
}

/** @return list<object> */
function get_the_category(int $id): array
{
    return array_map(static fn (int $t): object => (object) ['term_id' => $t], WpStub::$cats[$id] ?? []);
}

function is_singular(): bool
{
    return WpStub::$singular;
}

function get_queried_object_id(): int
{
    return WpStub::$queried;
}

function wp_next_scheduled(string $hook): int|false
{
    foreach (WpStub::$events as $e) {
        if ($e['hook'] === $hook) {
            return $e['time'];
        }
    }

    return false;
}

function wp_schedule_single_event(int $time, string $hook, array $args = []): bool
{
    WpStub::$events[] = ['hook' => $hook, 'time' => $time, 'recurrence' => null];

    return true;
}

function wp_schedule_event(int $time, string $recurrence, string $hook, array $args = []): bool
{
    WpStub::$events[] = ['hook' => $hook, 'time' => $time, 'recurrence' => $recurrence];

    return true;
}

function wp_clear_scheduled_hook(string $hook): int
{
    $before = count(WpStub::$events);
    WpStub::$events = array_values(array_filter(WpStub::$events, static fn (array $e): bool => $e['hook'] !== $hook));

    return $before - count(WpStub::$events);
}

/** @return array<string, mixed>|WP_Error */
function wp_remote_post(string $url, array $args = []): array|WP_Error
{
    WpStub::$requests[] = [$url, $args];
    if (WpStub::$http === null) {
        return new WP_Error('http_request_failed', 'No HTTP handler in tests.');
    }

    return (WpStub::$http)($url, $args);
}

/**
 * Records that the safe-URL guard was asked for, then answers like
 * wp_remote_post().
 *
 * @return array<string, mixed>|WP_Error
 */
function wp_safe_remote_post(string $url, array $args = []): array|WP_Error
{
    return wp_remote_post($url, $args + ['reject_unsafe_urls' => true]);
}

/** @return array<string, mixed>|WP_Error */
function wp_remote_get(string $url, array $args = []): array|WP_Error
{
    return wp_remote_post($url, $args + ['method' => 'GET']);
}

function is_wp_error(mixed $thing): bool
{
    return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code(mixed $r): int|string
{
    return is_array($r) ? (int) ($r['response']['code'] ?? 0) : '';
}

function wp_remote_retrieve_body(mixed $r): string
{
    return is_array($r) ? (string) ($r['body'] ?? '') : '';
}

function wp_remote_retrieve_header(mixed $r, string $name): string
{
    if (!is_array($r)) {
        return '';
    }
    foreach ((array) ($r['headers'] ?? []) as $k => $v) {
        if (strcasecmp((string) $k, $name) === 0) {
            return (string) $v;
        }
    }

    return '';
}

function current_user_can(string $cap): bool
{
    return !empty(WpStub::$caps[$cap]);
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    return $nonce === 'valid-' . $action ? 1 : false;
}

function wp_nonce_field(string $action, string $name = '_wpnonce', bool $referer = true, bool $echo = true): string
{
    return '<input type="hidden" name="' . $name . '" value="valid-' . $action . '">';
}

function wp_die(string $message = '', mixed $title = '', mixed $args = []): void
{
    throw new WpDieException($message);
}

function wp_safe_redirect(string $url): bool
{
    WpStub::$redirects[] = $url;

    return true;
}

function wp_salt(string $scheme = 'auth'): string
{
    return 'test-salt-' . $scheme;
}

function wp_mkdir_p(string $dir): bool
{
    if (is_dir($dir)) {
        return true;
    }
    $parent = $dir;
    while (($parent = dirname($parent)) !== '/' && !file_exists($parent)) {
        // walk up to the first existing ancestor
    }
    if (!is_dir($parent)) {
        return false;
    }

    return mkdir($dir, 0755, true);
}

function get_current_blog_id(): int
{
    return WpStub::$blog;
}

function is_multisite(): bool
{
    return WpStub::$multisite;
}

/** @return list<int> */
function get_sites(array $args = []): array
{
    return WpStub::$sites;
}

function switch_to_blog(int $id): bool
{
    global $wpdb;
    WpStub::$blogStack[] = WpStub::$blog;
    WpStub::$blog = $id;
    if ($wpdb instanceof wpdb) {
        $wpdb->prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
    }

    return true;
}

function restore_current_blog(): bool
{
    global $wpdb;
    $id = array_pop(WpStub::$blogStack) ?? 1;
    WpStub::$blog = $id;
    if ($wpdb instanceof wpdb) {
        $wpdb->prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
    }

    return true;
}

function register_rest_route(string $ns, string $route, array $args): bool
{
    WpStub::$routes[$ns . $route] = $args;

    return true;
}

function add_shortcode(string $tag, callable $cb): void
{
    WpStub::$shortcodes[$tag] = $cb;
}

function register_block_type(string $path, array $args = []): object
{
    WpStub::$blocks[] = $path;

    return (object) ['name' => $path, 'args' => $args];
}

function wp_register_script(string $handle, string $src, array $deps = [], mixed $ver = false, mixed $footer = false): bool
{
    return true;
}

function add_management_page(string $title, string $menu, string $cap, string $slug, callable $cb): string
{
    return 'tools_page_' . $slug;
}

function wp_add_privacy_policy_content(string $name, string $text): void
{
    WpStub::$privacy[] = $text;
}

function dbDelta(string $sql): array
{
    WpStub::$dbDelta[] = $sql;
    if (WpStub::$dbDeltaCallback !== null) {
        (WpStub::$dbDeltaCallback)($sql);
    }

    return [];
}
