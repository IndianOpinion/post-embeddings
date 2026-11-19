<?php

declare(strict_types=1);

// phpcs:disable PSR1.Files.SideEffects

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/post-embeddings-tests-' . getmypid();
define('ABSPATH', $tmp . '/');
define('WP_CONTENT_DIR', $tmp . '/wp-content');
if (!is_dir(WP_CONTENT_DIR)) {
    mkdir(WP_CONTENT_DIR, 0755, true);
}
register_shutdown_function(static function () use ($tmp): void {
    if (!is_dir($tmp)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($tmp);
});

require $root . '/tests/stubs/classes.php';
require $root . '/tests/stubs/WpStub.php';
require $root . '/tests/stubs/functions.php';
require $root . '/vendor/autoload.php';
require $root . '/functions.php';

$GLOBALS['wpdb'] = new wpdb();
