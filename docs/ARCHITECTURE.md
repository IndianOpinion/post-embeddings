# post-embeddings: architecture

This document describes how the plugin is built and records every decision taken while building it from [PRD.md](PRD.md), including the rulings on the PRD's open questions.

## Modules

| Namespace / file | Role |
|---|---|
| `post-embeddings.php`, `src/autoload.php` | Plugin header, 64-bit check, loads packed-vectors (Composer autoloader, else its `bootstrap.php`) and this plugin's classes, boots `Plugin`. |
| `Plugin` | Builds every service once and registers the hooks. Tests construct it with their own store, post source, clock and log writer. |
| `Settings`, `State` | Settings with defaults, validation and constants; runtime state (pause, cursors, last reports, cache generation) in one non-autoloaded option. |
| `Logger`, `Cache`, `Clock` | Rate-limited log lines plus the `post_embeddings_log` action; object cache or transients behind one prefix; injectable time. |
| `Provider\*` | `EmbeddingProvider` interface, `EmbeddingResult`, the typed `ProviderError` family, the reference `HttpEmbeddingProvider`, `FakeProvider`, and `ProviderResolver` (settings plus the `post_embeddings_provider` filter). |
| `Text\EmbeddingText`, `Text\TokenEstimator` | The embedding text, normalisation, truncation, hash; the per-script token estimate. |
| `Vector\VectorMath` | Validation, unit normalisation, float32 pack and unpack, cosine that refuses different lengths. |
| `Store\*` | `IndexStore` interface, `WpdbIndexStore` (the table), `MemoryIndexStore` (tests and examples), `Schema` (dbDelta plus `SHOW COLUMNS`). |
| `Posts\*` | `Eligibility` (the one definition of an eligible post) and `PostSource` (keyset walk of published posts for backfill and progress). |
| `Budget\*` | `Counters` (daily caps by UTC date), `SpendGate` and `Reservation` (optional spend meter). |
| `Index\*` | `Indexer` (save, transition, delete hooks), `Embedder` (one provider call and every outcome), `Drain`, `DrainLock`, `Backfill`. |
| `Sidecar\*` | `Directory` (location and deny files), `Sidecar` (rebuilds, scans with fallback), `VectorScan` (the scan seam). |
| `Query\*` | `Related`, `Search`, `HitRanker` (post-scan filtering and ordering), `RateLimiter`, `Scored`, `SearchOutcome`. |
| `Front\*` | Block and shortcode renderers, shared escaped markup, cache header, core search reordering. |
| `Rest\Routes`, `Cli\Runner`, `Cli\Command` | REST routes; the CLI logic free of WP-CLI (tested), and its thin WP-CLI glue. |
| `Admin\Screen`, `Health\*`, `Privacy`, `StatusReport`, `Uninstaller` | Tools screen and notices, Site Health and the exposure probe, privacy text, one status array for screen, CLI and REST, cleanup. |

## Data flow

```
save_post / transition_post_status
  -> Indexer: eligible? text hash changed? -> row pending (no network)

cron post_embeddings_drain (every drain_interval)
  -> Drain: lock, recheck types, mark bad stored rows
     loop: kill switch -> post cap -> pending rows (oldest updated_at)
           -> rebuild text, settle ineligible/empty -> trim to caps
           -> Embedder: spend meter -> provider -> validate -> store / back off / fail
  -> queue one rebuild if anything changed

cron post_embeddings_rebuild (60 s after a change, debounced) and post_embeddings_hourly
  -> Sidecar: protect dir (fail closed) -> keyset pages of 500 ok rows
     -> eligibility recheck -> packed-vectors Builder -> remove other models' files
     -> bump cache generation, store report, log

request: related block / shortcode / function / REST
  -> Related: row vector -> cache (blog, post, key, generation, row) -> Sidecar scan
     (UnavailableException -> chunked scan over the table + one rebuild)
     -> HitRanker -> cache 12 h -> render (or fallback)

request: search block / shortcode / function / REST
  -> Search: normalise, length -> cached query vector or (kill, search_enabled,
     rate limit, query cap, meter, 5 s call) -> scan -> HitRanker
     any failure -> keyword WP_Query, "Keyword results"
```

## Rulings on the PRD's open questions

1. **Default sidecar directory.** Ruling: a protected directory under `wp-content`, not `uploads`, with deny files; fail closed if they cannot be written. Built as: constant `POST_EMBEDDINGS_SIDECAR_DIR`, then the `post_embeddings_sidecar_dir` filter, then `wp-content/post-embeddings-private/`. Whichever directory is used gets `index.php`, a deny-all `.htaccess` (both Apache 2.4 and 2.2 syntax), a `web.config` and a small `probe.txt`. If any of them cannot be written, or a directory or symlink sits where one should be, the build stops before any vector is written, records `sidecar_error`, and requests use the chunked scan. Reason: `uploads` is the directory most often served, synced to object storage and backed up publicly; `wp-content` itself is rarely listed or synced, and writing the deny files everywhere costs nothing. The same rule applies to a custom directory, so a misconfigured path fails loudly rather than leaking.

2. **Vector storage.** Ruling: binary float32 `LONGBLOB` in the packed-vectors layout, written through `wpdb::prepare()` with a string placeholder; verify with `SHOW COLUMNS` after `dbDelta`. Built as: `vector longblob NULL`, exactly `4 * dim` bytes, `pack('g*')` little-endian. Findings:
   - `prepare()` escapes `%s` values with the driver's real-escape function, which handles NUL, quotes, backslash and 0x1A. A table with a BLOB column is treated as `binary` by wpdb's charset checks, so `query()` does not strip the value as invalid text. The integration test round-trips a blob holding every awkward byte against MySQL 8.0.
   - **Do not wrap a blob in `NULLIF(%s, '')`.** The first version wrote NULL-able columns that way to keep one literal statement; against real MySQL the blob came back empty. The vector now uses `IF(%d = 1, %s, NULL)` with an explicit flag. Only the real-database test caught this.
   - Every statement is a literal string with `%i` for table names (WordPress 6.2+, inside our 6.4 minimum). Integer id lists and escaped post type lists are appended after `prepare()`.
   - `Schema::install()` records the version option only when `SHOW COLUMNS` reports every column with the expected type; otherwise it stores the missing columns and the admin notice names them.

3. **Token estimate.** Ruling: characters divided by four with per-script ratios, filterable. Built as: characters per token of 4.0 (Latin and everything uncounted), 2.5 (Cyrillic, Greek, Armenian, Georgian), 2.0 (Arabic, Hebrew, Syriac, Thaana), 1.5 (Indic scripts, Sinhala, Tibetan, Ol Chiki; Thai, Lao, Khmer, Myanmar), 1.0 (Han, Kana, Hangul), 2.0 for other letters. `post_embeddings_token_ratios` changes the ratios and `post_embeddings_token_estimate` the result. Reason: a flat divide-by-four undercounts non-Latin text several times over, and undercounting a budget is the dangerous direction. The provider's reported usage replaces the estimate when present.

4. **Related cache invalidation.** Ruling: bump one cache version key on a rebuild rather than deleting per-post entries. Built as: a `generation` integer in the state option, bumped after every successful build, part of every related cache key with the blog id, post id, model key, the post's own row (`embedded_at`, text hash), the normalised arguments and the settings that change ranking. Old entries are never deleted; they expire (12 h). Reason: one write per build, no scan of cache keys, works the same with transients and persistent caches.

5. **Long posts.** Ruling: chunk vectors are a roadmap item; truncate in 0.1.0. Built as: truncation at `maxInputChars()` on a whitespace boundary within the last 200 characters. The table's primary key (`post_id`, `model_key`) would need a chunk column for chunking later; the packed-vectors `ref` field already allows many records per post, so the sidecar format would not change.

6. **Search pagination.** Ruling: no "more results" page. Built as: one page of `count` results and a "Search by keyword instead" link to core search.

7. **Second adapter.** Ruling: the HTTP adapter pointed at localhost is enough. Built as: `http://localhost` and `http://127.0.0.1` are the only plain-http base URLs accepted; `examples/02-local-model.php` shows it.

## Other decisions

- **Drain sends pending rows only.** After a model change or a new post type nothing is embedded on its own: new saves become pending under the new key, and the archive moves through `backfill` within the caps. This keeps one rule ("only saves and explicit backfills spend money") and matches case 5. The status screen shows "n of m posts under the current model".
- **`missing_in_response`** keeps the row `pending` with that error code and backs off by the normal retry rule; after `max_attempts` it becomes `error` / `gave_up`.
- **A per-input error code** from an adapter (`EmbeddingResult` errors) fails that row as `rejected_<code>`.
- **Same text that already failed** (`error` row, same hash) is not re-queued by a save; only `retry --errors` sends it again (case 26). Changed text is queued again.
- **Billing.** A timeout after sending, a malformed 200 response and an adapter that throws something outside its contract all count against the caps. A refused connection does not.
- **Spend meter shape.** The plugin sends `purpose`, `caller`, `provider`, `model`, `estimated_input_tokens` and accepts `reservation_id` (or `id`) in the decision. If the plugin's own budget moved while the meter was asked (another process spent it), the reservation is released and nothing is sent.
- **`daily_post_cap` of 0** stops indexing; only the token cap uses 0 for off.
- **`enabled` defaults to on.** With no provider configured the plugin is idle anyway, which gives the PRD's "on once a provider is saved".
- **Drain lock takeover** uses `update_option` and a re-read of a random token. Two takeovers racing on an expired lock could in theory both proceed; the window is one option write after a ten-minute expiry, and the worst case is one batch sent twice.
- **Post type recheck** after a settings change walks one page of 500 rows per drain run, so a large table is rechecked over several runs instead of one long request.
- **Corrupt stored rows** (wrong blob length) are skipped by the builder and the chunked scanner, remembered (at most 100 ids), and marked `error` / `bad_stored_vector` by the next drain.
- **Exposure probe** requests the sidecar file when it exists, else `probe.txt`, with `limit_response_size` of 256 bytes so a large file is never downloaded. Results are cached for 12 hours. A directory outside the content directory and ABSPATH is `not_public` and is not probed.
- **Search query vector cache key** is a SHA-256 of the model key and the lower-cased normalised query. The rate-limit key is an HMAC of the client address with `wp_salt('nonce')`.
- **Results filters** (`post_embeddings_related_results`, `post_embeddings_search_results`) receive `Query\Scored` objects (`->post`, `->score`) so a site can re-rank by score; the public PHP functions return plain `WP_Post` lists.
- **Network filesystems.** `post_embeddings_use_sidecar` returning false skips the file entirely and every scan uses the chunked scanner.
- **Uninstall** removes deny files and the directory only when no other site's sidecar files remain in it.
- **Logs** carry codes, counts and ids only. Context strings are cut to 400 characters; provider bodies are cut to 300 bytes with bearer tokens, key-like values and long tokens removed.

## Testing

- `tests/stubs/` is a small WordPress stand-in (options, transients, hooks, posts, `WP_Query`, cron, HTTP, REST and CLI classes), so PHPUnit runs without WordPress.
- `tests/Unit` maps each PRD acceptance test (T1 to T82) to a named test method with the T number in its docblock.
- `tests/Integration` runs `WpdbIndexStore`, `Schema` and `WpPostSource` against a real MySQL or MariaDB through a mysqli-backed `wpdb` with the same placeholders; CI starts a MySQL 8.0 service for it.
- Memory budgets (T82) are asserted with 10,064 stub posts at 64 dimensions; on PHP 8.1, which cannot reset the peak counter, the measured value is an over-estimate.
