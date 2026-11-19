<?php

/**
 * Plugin Name:       Post Embeddings
 * Plugin URI:        https://github.com/IndianOpinion/post-embeddings
 * Description:       One embedding per post, computed in the background, with a packed sidecar for fast scans, related-posts and semantic search blocks, and a capped backfill.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Indian Opinion
 * Author URI:        https://indianopinion.org
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       post-embeddings
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (PHP_INT_SIZE < 8) {
    add_action('admin_notices', static function (): void {
        if (current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>' . esc_html('Post Embeddings needs a 64-bit PHP build and is idle.') . '</p></div>';
        }
    });

    return;
}

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/functions.php';

register_activation_hook(__FILE__, static function (): void {
    \IndianOpinion\PostEmbeddings\Plugin::boot(__FILE__)->activate();
});
register_deactivation_hook(__FILE__, [\IndianOpinion\PostEmbeddings\Plugin::class, 'deactivate']);

\IndianOpinion\PostEmbeddings\Plugin::boot(__FILE__);
