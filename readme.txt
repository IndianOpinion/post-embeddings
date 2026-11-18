=== Post Embeddings ===
Contributors: indianopinion
Tags: related posts, semantic search, embeddings, search, similar posts
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

One embedding per post, computed in the background, with related-posts and semantic search blocks and a capped backfill.

== Description ==

Post Embeddings computes one embedding per published post in the background, stores it in its own table, keeps a packed sidecar file for fast similarity scans, and offers two blocks: related posts and semantic search.

Written from scratch; not derived from any third-party plugin.

* Saves never call the provider: posts are queued and a background drain embeds them.
* Daily caps for posts, tokens and search queries, a kill switch setting and constant.
* WP-CLI backfill with a required cap, resumable, with a dry run.
* Keyword results whenever a search cannot be embedded, never an error page.
* Works with any embeddings API that speaks the common HTTP request shape, or a self-hosted server on localhost.

Requires 64-bit PHP. Post text and search queries are sent to the provider you configure; private, draft and password-protected posts never are.

== Installation ==

1. Upload and activate the plugin.
2. Tools, Post embeddings: enter the base URL, model, dimensions and API key (or define POST_EMBEDDINGS_API_KEY in wp-config.php).
3. Set up a real cron that runs `wp cron event run --due-now` every five minutes.
4. Run `wp post-embeddings backfill --cap=500 --dry-run`, then without `--dry-run`.
5. Add the Related posts (embeddings) and Semantic search blocks.

On nginx, block the sidecar directory: `location ^~ /wp-content/post-embeddings-private/ { deny all; return 404; }`

== Frequently Asked Questions ==

= Nothing is being embedded =

Run `wp post-embeddings status` and check the pause, the kill switch, the caps and the last drain time.

= What does uninstall remove? =

The table, its options and transients, the sidecar files and the scheduled events, on every site.

== Changelog ==

= 0.1.0 =
* First release.
