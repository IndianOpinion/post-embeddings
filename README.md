# post-embeddings

A WordPress plugin that computes one embedding per published post in the background, keeps a packed sidecar file for fast similarity scans, and offers related-posts and semantic search blocks. It exists because tag-based "related posts" miss stories filed under different words, keyword search misses "fuel price rise" when the post says "petrol costs more", and doing embeddings carelessly either slows the editor, spends a month's budget in an hour, or falls over on a few thousand rows.

Written from scratch; not derived from any third-party plugin.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

Measured in production: at about 8,000 rows of 512-dimension JSON vectors the similarity step passed 10 s per request and one result set fatalled a 256 MB `memory_limit`; the same vectors packed as float32 (the sidecar this plugin keeps) were about 50 times faster to read.

## Contents

- [Requirements](#requirements)
- [Install](#install)
- [Quick start](#quick-start)
- [Configuration](#configuration)
- [Hooks](#hooks)
- [WP-CLI](#wp-cli)
- [Keeping the sidecar private](#keeping-the-sidecar-private)
- [Page caches and search](#page-caches-and-search)
- [How it works](#how-it-works)
- [Privacy](#privacy)
- [Limitations](#limitations)
- [FAQ](#faq)
- [Licence](#licence)

## Requirements

- WordPress 6.4 or later. PHP 8.1, 8.2 or 8.3, **64-bit** (on a 32-bit build the plugin shows a notice and stays idle).
- `mbstring`. `intl` is optional (used for Unicode NFC normalisation).
- An embeddings API you have an account with, under your own credentials, or a self-hosted embeddings server.
- Local disk for the sidecar file (atomic rename). On a network filesystem, turn the sidecar off (see FAQ).
- One runtime dependency: [`indianopinion/packed-vectors`](https://github.com/IndianOpinion/packed-vectors).

## Install

**From a release zip.** Download the release, upload it under Plugins, Add New, and activate. The zip carries `vendor/` with packed-vectors and its licence.

**With Composer** (packed-vectors is not on Packagist yet, so list both repositories):

```json
{
    "repositories": [
        {"type": "vcs", "url": "https://github.com/IndianOpinion/packed-vectors"},
        {"type": "vcs", "url": "https://github.com/IndianOpinion/post-embeddings"}
    ],
    "require": {"indianopinion/post-embeddings": "^0.1"}
}
```

**Without Composer, from a clone.** Put packed-vectors at `wp-content/plugins/post-embeddings/vendor/indianopinion/packed-vectors/` (the plugin then requires its `bootstrap.php`), and activate.

**As a must-use plugin.** Copy the plugin folder to `wp-content/mu-plugins/post-embeddings/` and copy [`examples/mu-plugins/post-embeddings-loader.php`](examples/mu-plugins/post-embeddings-loader.php) to `wp-content/mu-plugins/`. Activation hooks do not run there: the table is created on the first admin request and the cron events on `init`.

## Quick start

1. Tools, Post embeddings. Enter the base URL (for example `https://api.example.com/v1`; the plugin posts to `<base>/embeddings`), the model name, its dimensions, and the API key. Better: put the key in `wp-config.php` as `define('POST_EMBEDDINGS_API_KEY', '...');`.
2. Set up a real cron. WP-Cron only runs on page views, so on a quiet site the queue stalls:
   ```
   */5 * * * * cd /path/to/site && wp cron event run --due-now --quiet
   ```
3. Check what an archive run would send, then send it in capped runs:
   ```sh
   wp post-embeddings backfill --cap=500 --dry-run
   wp post-embeddings backfill --cap=500 --yes
   wp post-embeddings rebuild
   wp post-embeddings related 123      # look at the scores on a few posts
   ```
4. Add the **Related posts (embeddings)** block to your single-post template and the **Semantic search** block wherever you want a search box. In classic themes use `[post_embeddings_related]` and `[post_embeddings_search]`, or call `post_embeddings_related( $post_id )` from PHP.

From then on, every save of a published post queues it; the drain embeds it within a few minutes.

## Configuration

Settings live in Tools, Post embeddings. Every default passes through `post_embeddings_default_settings`.

| Setting | Default | What it does |
|---|---|---|
| `enabled` | on | Kill switch. Off means no provider call of any kind; related posts keep serving from stored vectors. `POST_EMBEDDINGS_DISABLED` set to `true` wins. |
| `search_enabled` | on | Turns off only search query embeddings (search then shows keyword results). |
| `post_types` | `post` | Post types to embed. Adding one embeds nothing until you run a backfill. |
| `base_url`, `model`, `dimensions`, `api_key` | empty | The HTTP adapter. `base_url` must be https; plain http is allowed for `localhost` and `127.0.0.1` only. |
| `max_input_chars` | 8000 | Text is cut at a whitespace boundary to this many characters. |
| `max_batch` | 64 | Inputs per provider call. |
| `send_dimensions` | off | Send `"dimensions"` in the request, for models that take it. |
| `min_chars` | 40 | Shorter texts are not embedded (status `empty`). |
| `batch_size` | 16 | Posts per drain batch, clamped to `max_batch`. |
| `drain_interval` | 300 s | How often the drain runs (60 to 3,600). |
| `drain_max_batches` | 4 | Batches per drain run. A run also stops after 20 s. |
| `max_attempts` | 8 | Transient failures before a post is given up (`gave_up`). |
| `daily_post_cap` | 500 | Posts embedded per UTC day for indexing and backfill. 0 stops indexing. |
| `daily_token_cap` | 0 (off) | Estimated input tokens per UTC day for indexing. |
| `daily_query_cap` | 1,000 | Fresh search query embeddings per UTC day. Cached queries are free. |
| `backfill_cap_ceiling` | 5,000 | The largest `--cap` a backfill accepts. |
| `min_score` | 0.30 | Related posts below this similarity are dropped. |
| `search_min_score` | 0.25 | The same for search. |
| `duplicate_score` | 0.98 | Hits this close to the post, or to a kept hit, are dropped as copies. 1.01 turns it off. |
| `overfetch` | 3 | The scan fetches `count * overfetch` hits (at most 200) to leave room for filtering. |
| `max_age_days` | 0 (off) | Drop related posts published this many days before the post. |
| `sidecar_max_age` | 3,600 s | The hourly check rebuilds a sidecar older than this. |
| `search_no_store` | off | Send `Cache-Control: private, max-age=0` on requests carrying the search parameter. |
| `log` | on | Write log lines to the PHP error log. |

Constants (in `wp-config.php`) override settings: `POST_EMBEDDINGS_DISABLED`, `POST_EMBEDDINGS_API_KEY`, `POST_EMBEDDINGS_SIDECAR_DIR`.

**Score thresholds depend on the model.** 0.30 is a starting point, not a truth. Run `wp post-embeddings related <id>` on a few posts you know well and look at where the good matches stop before changing it.

**Large sites.** The scan is exact and linear. Near 100,000 posts, lower `overfetch` or give the scan more time; the packed-vectors library publishes about 0.84 s for 10,000 x 512.

## Hooks

Filters:

| Hook | Arguments | Purpose |
|---|---|---|
| `post_embeddings_provider` | `?EmbeddingProvider $p, array $settings` | Return any adapter. Default: the HTTP adapter when configured, else `null` (idle). |
| `post_embeddings_is_eligible` | `bool $ok, WP_Post $post` | Exclude posts. |
| `post_embeddings_text` | `string $text, WP_Post $post` | Replace the text that is embedded. |
| `post_embeddings_post_types` | `array $types` | Post types to embed. |
| `post_embeddings_related_args` | `array $args, int $post_id` | `count`, `post_types`, `min_score`. |
| `post_embeddings_related_results` | `array $results, int $post_id, array $args` | Reorder or trim; items are `Query\Scored` (`->post`, `->score`). |
| `post_embeddings_related_html` | `string $html, array $posts, array $attrs` | Replace the markup. |
| `post_embeddings_search_results` | `array $results, string $query, string $mode` | Reorder or trim; `Query\Scored` items. |
| `post_embeddings_search_param` | `string $name` | Query parameter, default `semantic`. |
| `post_embeddings_replace_core_search` | `bool $on` | Reorder the core search page by semantic score. Default false. |
| `post_embeddings_sidecar_dir` | `string $dir` | Sidecar directory. |
| `post_embeddings_use_sidecar` | `bool $on` | False uses only the chunked table scan (network filesystems). |
| `post_embeddings_rate_limit` | `array{limit, window}` | Default 10 fresh query embeddings per 600 s per client. |
| `post_embeddings_trust_proxy_headers` | `bool $on` | Use the first `X-Forwarded-For` address for the rate limit. Default false. |
| `post_embeddings_capability` | `string $cap` | Default `manage_options`. |
| `post_embeddings_default_settings` | `array $defaults` | Change any default. |
| `post_embeddings_extra_headers` | `array $headers` | Extra HTTP headers for the adapter (cannot set `Authorization`). |
| `post_embeddings_token_ratios`, `post_embeddings_token_estimate` | | Tune the token estimate (see How it works). |

Actions: `post_embeddings_stored( $post_id, $model_key )`, `post_embeddings_failed( $post_id, $code )`, `post_embeddings_sidecar_built( $report )`, `post_embeddings_cap_reached( $which, $limit )`, `post_embeddings_paused( $reason )`, `post_embeddings_log( $level, $code, $context )`.

REST, under `post-embeddings/v1`: `GET /related/<id>?count=` (public, published posts only, 404 otherwise), `GET /search?q=&count=` (public, rate-limited, returns `{"mode": "semantic"|"keyword", "results": [...]}`), `GET /status` (manage capability). Scores appear only for users with the manage capability.

**Spend meter.** When a callback is hooked on `spend_meter_pre_spend`, each provider call first asks it with purpose `post-embeddings.index` or `post-embeddings.query`. A decision whose `allowed` is not exactly `true` means no call. The plugin then fires `spend_meter_record` with the `reservation_id`, or `spend_meter_release` when the call did not happen. With no meter, the plugin's own caps apply; they always apply anyway.

**Your own provider.** Implement `IndianOpinion\PostEmbeddings\Provider\EmbeddingProvider` (`id`, `model`, `dimensions`, `maxInputChars`, `maxBatch`, `embed`) and return it from `post_embeddings_provider`. Throw only the plugin's `ProviderError` exceptions for provider problems. `FakeProvider` gives deterministic vectors from a hash for tests and local development: **its results are not semantic.** See [`examples/`](examples).

## WP-CLI

```
wp post-embeddings backfill --cap=<n> [--post-type=<a,b>] [--order=newest|oldest] [--since=<YYYY-MM-DD>] [--batch=<n>] [--dry-run] [--resume] [--yes]
wp post-embeddings drain | status [--format=json] | rebuild | verify
wp post-embeddings related <post_id> [--count=<n>]
wp post-embeddings search "<text>"        # counts against the query cap
wp post-embeddings retry --errors | resume | prune --model=<key> [--yes] | kill on|off
```

`backfill` refuses to run without `--cap` and refuses a cap above `backfill_cap_ceiling`. It stops with exit code 0 and a message when a cap or the kill switch stops it; `--resume` carries on from the stored cursor. Ctrl-C between batches loses nothing. Every command exits non-zero on failure.

## Keeping the sidecar private

Vectors can leak information about the text they came from. The default directory is `wp-content/post-embeddings-private/`; the plugin writes an `index.php`, a deny-all `.htaccess` (Apache) and a `web.config` (IIS) into it and **refuses to build if it cannot write them**. On activation and in Site Health it asks the site for a file in that directory over HTTP; a `200` is reported as critical.

nginx ignores `.htaccess`. Add this to the server block:

```nginx
location ^~ /wp-content/post-embeddings-private/ { deny all; return 404; }
```

Best of all, keep the file outside the document root:

```php
define('POST_EMBEDDINGS_SIDECAR_DIR', '/var/lib/post-embeddings');
```

The directory must be writable by PHP. The sidecar is derived data: deleting it is safe; the next rebuild recreates it from the table.

## Page caches and search

Related lists are part of the cached page and change when the page cache expires or is purged. Expect a new post to appear in other posts' lists after the next expiry.

Search results vary by the query parameter (`?semantic=...`). Most page caches (nginx FastCGI cache with the full request URI in the key, most CDN defaults, most WordPress cache plugins) already key on the full URL, so cached search pages are correct and cheap: leave `search_no_store` off. Turn it on if your cache ignores query strings, or if you do not want search pages stored at all; the plugin then sends `Cache-Control: private, max-age=0` on those requests only.

## How it works

1. **Save.** `save_post` and `transition_post_status` compute the post's text and a SHA-256 of it joined with the model key. Unchanged text with a stored vector does nothing; changed text marks the row `pending`. No network call ever happens in a save. Unpublishing marks the row `ineligible`; republishing unchanged text restores it without a call.
2. **Text.** Title, a blank line, the manual excerpt, a blank line, the content. The `the_content` filters never run: block comments, shortcodes and tags are stripped and entities decoded. Every script is kept as is (NFC when `intl` is present). Long text is cut at a whitespace boundary.
3. **Drain.** A cron event takes pending rows, oldest first, in batches. Before each call it checks the kill switch, the daily caps and the spend meter; batches are trimmed so they never cross a cap. It runs under an option lock and stops after 20 s. Each returned vector is checked (a list, finite numbers, the declared length, non-zero norm), normalised to unit length and stored as float32. Failures back off (5 min, 15 min, 1 h, 4 h, then 12 h, or longer when `Retry-After` says so); 401 and 403 pause the drain until the settings are saved or `resume` runs.
4. **Token estimate.** When the provider reports no usage, tokens are estimated as characters divided by a per-script ratio: about 4 for Latin, 2.5 for Cyrillic and Greek, 2 for Arabic and Hebrew, 1.5 for Indic and Southeast Asian scripts, 1 for CJK. A flat divide-by-four undercounts non-Latin text, and undercounting a budget is the dangerous direction. A call that may have been billed (a timeout after sending) counts.
5. **Sidecar.** After changes, one rebuild is queued 60 s ahead (debounced). It streams `ok` rows of the current model by post id in keyset pages of 500, re-checks eligibility, and hands them to the packed-vectors builder, which writes a temporary file and renames it into place. The file name carries the blog id and a hash of the model key, so a model change never mixes dimensions. Each successful build bumps one cache generation number, which retires every cached related list at once.
6. **Related.** One primary-key read for the post's vector, a scan of the sidecar for `count * overfetch` hits above `min_score` within 0.3 s, then filtering in PHP: drop the post itself, ineligible posts, other types, old posts and near-copies; order by score, then newer date, then lower id. Results are cached for 12 h. If the sidecar is missing, corrupt or of another dimension, the chunked scanner reads the table instead for that request (1.5 s budget) and one rebuild is queued.
7. **Search.** The query is normalised and length-checked (3 to 200 characters). Its vector is cached for 7 days under a hash; the text itself is never stored. A fresh embedding must pass the kill switch, `search_enabled`, a per-client rate limit, the query cap and the spend meter, and gets a 5 s timeout. Any failure shows keyword results headed "Keyword results", never an error.

## Privacy

The text of published, public posts goes to your provider. Private, draft, scheduled and password-protected posts are never sent. Readers' search queries go to the provider too; the plugin never stores or logs them, keeps only the query vector under a one-way hash, and counts searches per client under a salted hash of the address. A suggested privacy-policy paragraph is offered in Settings, Privacy.

## Limitations

- One vector per post. Long posts are truncated, so the end of a very long post does not count.
- Exact linear scan: good up to about 100,000 posts. No approximate index, no external vector database.
- Search shows one page of results.
- Related lists update with the page cache, not instantly.
- 64-bit PHP and local disk with atomic rename for the sidecar.
- One reference adapter (the common HTTP embeddings shape). Other providers need a small adapter class.

## FAQ

**Nothing is being embedded.** Run `wp post-embeddings status`. Look at "Paused", "Kill switch", today's caps, and "Last drain". If the last drain is old, WP-Cron is not running: add the system cron above.

**I changed the model. What happens?** The model key changes. Old rows stay (remove them later with `prune --model=<old key>`), related posts show their fallback until posts are re-embedded, and nothing is sent until the drain picks up new saves or you run a backfill. The status screen shows "n of m posts under the current model".

**Can I use a local model?** Yes. Point the base URL at `http://127.0.0.1:<port>/v1` on a server that speaks the common embeddings request shape. See [`examples/02-local-model.php`](examples/02-local-model.php).

**My files live on NFS or another network filesystem.** Turn the sidecar off: `add_filter('post_embeddings_use_sidecar', '__return_false');`. Every request then uses the chunked table scan, which is slower but safe.

**Why are there no scores on the page?** Readers never see scores. `wp post-embeddings related <id>` and the REST routes (for administrators) show them.

**How do I see what it is doing?** The status screen (Tools, Post embeddings), `wp post-embeddings status`, Site Health, the `[post-embeddings]` lines in the PHP error log, and the `post_embeddings_log` action.

**What does uninstall remove?** The table, every option and transient with the plugin's prefix, the sidecar and lock files, and the scheduled events, on every site of a network. Deactivation only unschedules events.

## Licence

MIT. See [LICENSE](LICENSE). Contributions are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md). Report security problems as described in [SECURITY.md](SECURITY.md).
