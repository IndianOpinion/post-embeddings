<?php

/**
 * Minimal WordPress classes for the tests. Only what the plugin touches.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, Squiz.Classes.ValidClassName.NotCamelCaps, PSR1.Methods.CamelCapsMethodName.NotCamelCaps

final class WP_Post
{
    public int $ID = 0;
    public string $post_title = '';
    public string $post_content = '';
    public string $post_excerpt = '';
    public string $post_status = 'publish';
    public string $post_type = 'post';
    public string $post_password = '';
    public string $post_name = '';
    public string $post_date = '2026-01-01 00:00:00';
    public string $post_date_gmt = '2026-01-01 00:00:00';
    public int $post_parent = 0;

    /** @param array<string, mixed> $props */
    public function __construct(array $props = [])
    {
        foreach ($props as $k => $v) {
            $this->{$k} = $v;
        }
    }
}

class WP_Error
{
    /** @param array<string, mixed>|mixed $data */
    public function __construct(public string $code = '', public string $message = '', public mixed $data = null)
    {
    }

    public function get_error_message(): string
    {
        return $this->message;
    }

    public function get_error_code(): string
    {
        return $this->code;
    }

    public function get_error_data(): mixed
    {
        return $this->data;
    }
}

final class WP_REST_Request
{
    /** @param array<string, mixed> $params */
    public function __construct(private array $params = [])
    {
    }

    public function get_param(string $key): mixed
    {
        return $this->params[$key] ?? null;
    }
}

final class WP_REST_Response
{
    public function __construct(public mixed $data = null, public int $status = 200)
    {
    }

    public function get_data(): mixed
    {
        return $this->data;
    }

    public function get_status(): int
    {
        return $this->status;
    }
}

final class WP_Site
{
    public function __construct(public int $blog_id)
    {
    }
}

class WP_Query
{
    /** @var list<WP_Post> */
    public array $posts = [];

    /** @var array<string, mixed> */
    public array $query_vars = [];

    public bool $main = false;
    public bool $search = false;

    /** @param array<string, mixed> $args */
    public function __construct(array $args = [])
    {
        $this->query_vars = $args;
        if ($args === []) {
            return;
        }
        WpStub::$queries[] = $args;
        if (WpStub::$queryThrows !== null) {
            throw WpStub::$queryThrows;
        }
        $this->posts = WpStub::runQuery($args);
    }

    public function get(string $key): mixed
    {
        return $this->query_vars[$key] ?? '';
    }

    public function is_main_query(): bool
    {
        return $this->main;
    }

    public function is_search(): bool
    {
        return $this->search;
    }
}

class wpdb
{
    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $options = 'wp_options';
    public string $last_error = '';

    /** @var list<string> */
    public array $queries = [];

    public function prepare(string $query, mixed ...$args): string
    {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $i = 0;
        $out = preg_replace_callback('/%%|%([dsfi])/', function (array $m) use (&$i, $args): string {
            if ($m[0] === '%%') {
                return '%';
            }
            $v = $args[$i++] ?? null;

            return match ($m[1]) {
                'd' => (string) (int) $v,
                'f' => (string) (float) $v,
                'i' => '`' . str_replace('`', '``', (string) $v) . '`',
                default => "'" . $this->_real_escape((string) $v) . "'",
            };
        }, $query);

        return (string) $out;
    }

    public function _real_escape(string $s): string
    {
        return addslashes($s);
    }

    public function esc_like(string $text): string
    {
        return addcslashes($text, '_%\\');
    }

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
    }

    public function query(string $sql): int|bool
    {
        $this->queries[] = $sql;

        return 0;
    }

    public function get_var(string $sql): mixed
    {
        $this->queries[] = $sql;

        return null;
    }

    /** @return array<string, mixed>|null */
    public function get_row(string $sql, string $output = 'OBJECT'): ?array
    {
        $this->queries[] = $sql;

        return null;
    }

    /** @return list<mixed> */
    public function get_results(string $sql, string $output = 'OBJECT'): array
    {
        $this->queries[] = $sql;

        return [];
    }

    /** @return list<mixed> */
    public function get_col(string $sql): array
    {
        $this->queries[] = $sql;

        return [];
    }
}

final class WP_CLI
{
    /** @var list<string> */
    public static array $lines = [];

    public static function line(string $line): void
    {
        self::$lines[] = $line;
    }

    public static function halt(int $code): void
    {
        throw new RuntimeException('halt ' . $code);
    }

    public static function add_command(string $name, mixed $callable): void
    {
        WpStub::$cli[$name] = $callable;
    }
}

final class WpDieException extends RuntimeException
{
}
