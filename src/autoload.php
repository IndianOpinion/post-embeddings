<?php

/**
 * Loads packed-vectors and this plugin's classes, with or without Composer.
 *
 * Order: a Composer autoloader beside the plugin (a release zip ships one
 * with the library under vendor/), else the library's bootstrap file, else
 * whatever autoloader the site already has.
 */

declare(strict_types=1);

$postEmbeddingsRoot = dirname(__DIR__);
if (is_file($postEmbeddingsRoot . '/vendor/autoload.php')) {
    require_once $postEmbeddingsRoot . '/vendor/autoload.php';
} elseif (
    !class_exists(\IndianOpinion\PackedVectors\Builder::class)
    && is_file($postEmbeddingsRoot . '/vendor/indianopinion/packed-vectors/bootstrap.php')
) {
    require_once $postEmbeddingsRoot . '/vendor/indianopinion/packed-vectors/bootstrap.php';
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'IndianOpinion\\PostEmbeddings\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
unset($postEmbeddingsRoot);
