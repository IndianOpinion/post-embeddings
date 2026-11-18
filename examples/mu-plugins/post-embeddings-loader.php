<?php

/**
 * Plugin Name: Post Embeddings (must-use loader)
 * Description: Loads Post Embeddings from wp-content/mu-plugins/post-embeddings/.
 *
 * Copy this file to wp-content/mu-plugins/ and the plugin folder to
 * wp-content/mu-plugins/post-embeddings/. Activation hooks do not run for
 * must-use plugins: the table is created on the first admin request, and the
 * cron events are scheduled on `init`.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/post-embeddings/post-embeddings.php';
