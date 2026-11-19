<?php

declare(strict_types=1);

namespace IndianOpinion\PostEmbeddings\Sidecar;

/**
 * Where sidecar files live, and the deny files that protect the directory.
 *
 * Order: the POST_EMBEDDINGS_SIDECAR_DIR constant, then the
 * `post_embeddings_sidecar_dir` filter, then `wp-content/post-embeddings-private`.
 * The plugin writes an index.php, a deny-all .htaccess and a web.config into
 * whichever directory is used, and refuses to build (fails closed) when any
 * of them cannot be written.
 */
final class Directory
{
    public const DEFAULT_NAME = 'post-embeddings-private';
    public const PROBE = 'probe.txt';
    public const PROBE_BODY = "post-embeddings deny probe\n";

    public function path(): string
    {
        $dir = '';
        if (defined('POST_EMBEDDINGS_SIDECAR_DIR') && is_string(constant('POST_EMBEDDINGS_SIDECAR_DIR'))) {
            $dir = (string) constant('POST_EMBEDDINGS_SIDECAR_DIR');
        }
        if ($dir === '') {
            $default = rtrim(WP_CONTENT_DIR, '/\\') . '/' . self::DEFAULT_NAME;
            $filtered = apply_filters('post_embeddings_sidecar_dir', $default);
            $dir = is_string($filtered) && $filtered !== '' ? $filtered : $default;
        }

        return rtrim($dir, '/\\');
    }

    /** @return array<string, string> file name => contents */
    public static function denyFiles(): array
    {
        return [
            'index.php' => "<?php\n// Silence is golden.\n",
            '.htaccess' => "# Written by post-embeddings. Deny all web access.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
                . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n"
                . "    <security>\n      <authorization>\n        <remove users=\"*\" roles=\"\" verbs=\"\" />\n"
                . "        <add accessType=\"Deny\" users=\"*\" />\n      </authorization>\n    </security>\n"
                . "  </system.webServer>\n</configuration>\n",
            self::PROBE => self::PROBE_BODY,
        ];
    }

    /**
     * Creates the directory and its deny files. Null on success, otherwise
     * the reason. Callers must not write vectors when this fails.
     */
    public function protect(): ?string
    {
        $dir = $this->path();
        if (!self::isAbsolute($dir)) {
            // A relative path would follow the working directory of whatever
            // process runs the build: refuse rather than write vectors there.
            return 'directory_not_absolute';
        }
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return 'directory_missing';
        }
        if (!is_writable($dir)) {
            return 'directory_not_writable';
        }
        foreach (self::denyFiles() as $name => $body) {
            $path = $dir . '/' . $name;
            if (is_file($path) && file_get_contents($path) === $body) {
                continue;
            }
            if (is_link($path) || is_dir($path) || file_put_contents($path, $body) !== strlen($body)) {
                return 'deny_file_' . $name;
            }
        }

        return null;
    }

    /** True when every deny file is in place with the expected body. */
    public function protectedNow(): bool
    {
        $dir = $this->path();
        foreach (self::denyFiles() as $name => $body) {
            $path = $dir . '/' . $name;
            if (!is_file($path) || file_get_contents($path) !== $body) {
                return false;
            }
        }

        return true;
    }

    /**
     * A URL for a path in the directory when the directory sits under the
     * document root (content dir or ABSPATH), else null.
     */
    public function urlFor(string $name): ?string
    {
        $dir = $this->real($this->path());
        $content = $this->real(rtrim(WP_CONTENT_DIR, '/\\'));
        if ($dir !== null && $content !== null && str_starts_with($dir . '/', $content . '/')) {
            return rtrim(content_url(), '/') . substr($dir, strlen($content)) . '/' . $name;
        }
        $root = defined('ABSPATH') ? $this->real(rtrim(ABSPATH, '/\\')) : null;
        if ($dir !== null && $root !== null && str_starts_with($dir . '/', $root . '/')) {
            return rtrim(site_url(), '/') . substr($dir, strlen($root)) . '/' . $name;
        }

        return null;
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[/\\\\]#', $path) === 1;
    }

    /**
     * The resolved path, or for a path that does not exist yet its deepest
     * existing parent resolved plus the rest, so a directory that has not
     * been created is still placed correctly against the document root.
     */
    private function real(string $path): ?string
    {
        $real = realpath($path);
        if ($real !== false) {
            return rtrim($real, '/\\');
        }
        $rest = [];
        $current = rtrim($path, '/\\');
        while ($current !== '' && $current !== '.' && $current !== dirname($current)) {
            $rest[] = basename($current);
            $current = dirname($current);
            $base = realpath($current);
            if ($base !== false) {
                $out = explode('/', rtrim($base, '/\\'));
                foreach (array_reverse($rest) as $part) {
                    if ($part === '..') {
                        if (count($out) > 1) {
                            array_pop($out);
                        }
                    } elseif ($part !== '' && $part !== '.') {
                        $out[] = $part;
                    }
                }

                return implode('/', $out);
            }
        }

        return null;
    }
}
