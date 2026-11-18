<?php

/**
 * Removes the index table, every option and transient with the plugin's
 * prefix, the sidecar files and the scheduled events, on every site.
 */

declare(strict_types=1);

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once __DIR__ . '/src/autoload.php';

global $wpdb;
$postEmbeddingsPlugin = new \IndianOpinion\PostEmbeddings\Plugin(__DIR__ . '/post-embeddings.php');
(new \IndianOpinion\PostEmbeddings\Uninstaller($wpdb, $postEmbeddingsPlugin->sidecar, $postEmbeddingsPlugin->directory))->all();
unset($postEmbeddingsPlugin);
