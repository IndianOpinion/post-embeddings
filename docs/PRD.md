# post-embeddings: product requirements

Status: draft for review. Audience: the builder, the code reviewer and anyone
deciding whether to install the plugin. This document describes behaviour,
not code. Where it says "must", an acceptance test in section 12 checks it.
Names of classes, hooks, options, tables and commands here are proposals for
the public API of this plugin; the builder may refine them and must record
every change in `docs/ARCHITECTURE.md`.

This plugin is written from scratch. It is not derived from any third-party
plugin, and it names none.

## 1. Problem

A WordPress site with a few thousand posts has two weak spots that keyword
matching cannot fix:

- "Related posts" lists are built from shared tags or categories. They miss
  stories about the same subject filed under different terms, and they repeat
  the same few popular posts everywhere.
- The core search box matches words. A reader who types "fuel price rise"
  gets nothing from a post headed "Petrol and diesel cost more from Monday".

Embeddings fix both: one vector per post, and a cosine comparison between
vectors. The hard part is not the maths. It is everything around it:

- Computing an embedding costs money and takes a network call. Doing it
  inline on save slows the editor; doing it for the whole archive at once
  can spend a month's budget in an hour.
- Comparing vectors kept as JSON in database rows does not scale. Measured
  in production (published with the `packed-vectors` library): at about
  8,000 rows of 512-dimension JSON vectors the similarity step passed 10 s
  per request and one result set fatalled a 256 MB `memory_limit`; the same
  vectors packed as float32 were about 50 times faster to read.
- A cosine function that quietly compares the overlapping part of two
  vectors of different lengths gives plausible numbers that are wrong. After
  a model change nothing errors; results just get worse.
- A zero or empty vector, a failed decode, or a provider outage must not
  take a page down.

post-embeddings computes one embedding per published post in the
background, stores it in its own table, keeps a packed sidecar file for fast
scans, and offers two blocks: related posts and semantic search. A WP-CLI
backfill embeds the archive in capped runs. A daily cap and a kill switch
bound spending.

## 2. Goals and non-goals

Goals:

1. Compute one embedding per eligible post through a provider adapter
   interface, in the background, only when the post's text has changed.
2. Store every vector with the model identity, dimension and a hash of the
   text it came from, in a table this plugin owns.
3. Keep a packed sidecar file built with `indianopinion/packed-vectors`, so
   a request scans vectors without reading rows from the database.
4. Offer a server-rendered "Related posts" block and a "Semantic search"
   block, plus PHP functions, shortcodes and REST routes that return the
   same results.
5. Offer a WP-CLI backfill with a required cap, resumable, with a dry run.
6. Bound spending: a daily cap for indexing, a separate daily cap for search
   queries, a kill switch in the plugin's own settings, and optional
   integration with `spend-meter` when it is present.
7. Fail safe on every request path: if anything is missing, slow or broken,
   render a fallback or nothing, never an error page.
8. Ship one reference provider adapter for the common HTTP embeddings
   request shape, and a filter so a site can plug in any other provider.

Non-goals:

1. Generating or rewriting text. The plugin never changes post content.
2. Chunking a post into several vectors. One vector per post. Long posts
   are truncated (case 9).
3. Approximate nearest-neighbour indexes or an external vector database.
   The scan is exact and linear, which the sidecar keeps fast up to about
   100,000 posts.
4. Replacing the WordPress core search query by default. An opt-in filter
   exists (case 60); the default leaves core search alone.
5. Clustering, deduplication or topic detection as features. The related
   list hides near-identical posts (case 47), nothing more.
6. Embedding comments, users, terms or media. Posts of chosen post types
   only.
7. Bundling any SDK. Provider calls use the WordPress HTTP API.
8. Adapters for any third-party plugin.

## 3. Users and setting

- **Who installs it:** a site owner or developer with `manage_options` and
  shell access for WP-CLI. Editors see only the blocks.
- **Where it runs:** a single WordPress site or each site of a multisite
  network (per-site data, section 6). Normal plugin; it may also run as a
  must-use plugin through a loader stub (section 10).
- **Readers** see the blocks' output. They never see scores, errors or
  provider names.
- **Background work** runs on WP-Cron or a real system cron that calls
  `wp cron event run --due-now`. The README must recommend a real cron for
  sites with little traffic, because WP-Cron only runs on page views.
- **Provider:** any embeddings API the operator has an account with, under
  the operator's own credentials, through its official documented
  interface.

## 4. Behaviour

Terms used below:

- **Model key:** the string `<adapter id>:<model>:<dimension>`, for example
  `http:my-model:512`. Two vectors are comparable only if their model keys
  are equal.
- **Eligible post:** post status `publish`, post type in the configured list
  (default `post`), no password, not excluded by the
  `post_embeddings_is_eligible` filter.
- **Embedding text:** the text sent to the provider for a post (case 7).
- **Text hash:** SHA-256 of the embedding text after normalisation, joined
  with the model key.
- **Index row:** the stored record for one post (section 6).
- **Sidecar:** the packed-vectors file for the current model key.

### 4.1 Deciding what to embed

1. On `save_post` for an eligible post the plugin computes the text hash.
   If an index row exists with the same model key and text hash and status
   `ok`, nothing happens. Otherwise the row is set to status `pending` (or
   created as `pending`). No provider call happens inside the save request.
2. Autosaves, revisions, auto-drafts and `nav_menu_item` posts are ignored.
   A save from the REST API, the classic editor, XML-RPC, WP-CLI or
   `wp_insert_post()` in code behaves the same.
3. A post that moves from published to draft, pending, private, future or
   trash keeps its index row, marked `ineligible`. It drops out of results at
   the next sidecar build and is filtered at request time before then
   (case 41). If it is published again with unchanged text, the row returns
   to `ok` without a provider call.
4. A post deleted permanently (`deleted_post`) has its index row removed and
   a sidecar rebuild queued.
5. A post that becomes ineligible because its post type is removed from the
   settings list is treated as case 3 at the next drain or backfill. Adding
   a post type does not embed anything on its own; the operator runs a
   backfill (section 4.5).
6. A scheduled post is embedded when it publishes (`transition_post_status`
   to `publish`), not when it is scheduled.

### 4.2 The embedding text

7. The embedding text is built from: the title, a blank line, the excerpt if
   the post has a manual excerpt, a blank line, then the content. Content is
   rendered without running the `the_content` filter chain (no shortcodes
   expanded, no embeds fetched): block comments are removed, shortcodes are
   stripped with `strip_shortcodes()`, HTML tags are removed, HTML entities
   are decoded, and runs of whitespace become one space. The
   `post_embeddings_text` filter receives the result and the post and may
   return a replacement string.
8. Normalisation keeps every Unicode letter, mark and number. Text in any
   script (Devanagari, Tamil, Arabic, CJK and so on) passes through
   unchanged apart from Unicode NFC normalisation when the `intl` extension
   is present. Nothing in the pipeline may assume ASCII.
9. The text is truncated to the adapter's `maxInputChars()` (default 8,000
   characters, counted with `mb_strlen`), cut at the last whitespace before
   the limit if there is one within the final 200 characters, otherwise at
   the limit. Truncation never splits a multibyte character.
10. Empty embedding text (no title and no content after cleaning) sets the
    row to status `empty`. No provider call. The post is left out of the
    sidecar and gets no related list of its own.
11. Text shorter than the `min_chars` setting (default 40) is also `empty`.
    Short stubs embed badly and are mostly navigation pages.

### 4.3 Computing embeddings (the drain)

12. A recurring event (`post_embeddings_drain`, every 5 minutes by default)
    takes `pending` rows in order of oldest `updated_at` first, up to
    `batch_size` posts (default 16), rebuilds each post's text, and calls the
    adapter once for the whole batch.
13. Before each call it checks, in this order: the kill switch (case 30),
    the indexing daily cap (case 27), and `spend-meter` when present
    (case 33). If any says no, the drain stops for this run, and rows stay
    `pending`.
14. The drain runs at most `drain_max_batches` batches per run (default 4)
    and stops early once 20 seconds of wall time have passed, so one cron
    run never holds a PHP worker for long.
15. A drain run takes a lock (an option row written with `add_option`,
    holding an expiry 10 minutes ahead) so two overlapping cron runs never
    send the same posts twice. A lock older than its expiry is taken over.
16. For each vector the provider returns, the plugin checks: it is a list;
    every value is a finite number; the length equals the adapter's
    declared dimension; the norm is not zero. A vector that fails any check
    is not stored: the row becomes `error` with code `bad_vector_<reason>`
    and is not retried automatically, because the same input will fail the
    same way. A dimension mismatch also writes one warning to the log (case
    67), because it usually means the model setting is wrong.
17. A stored vector is normalised to unit length in double precision and
    then packed as float32 little-endian. The row records the model key,
    dimension, text hash, token count when the provider reports it, and
    `embedded_at` in UTC.
18. If the provider returns fewer vectors than inputs, or vectors out of
    order with an index field, the adapter must map each vector to its input
    by index. Inputs without a vector become `error` with code
    `missing_in_response` and are retried by the normal retry rule (case
    20).
19. After a drain run that stored at least one vector or changed at least
    one row's eligibility, a sidecar rebuild is queued (case 35).

### 4.4 Provider failures

20. **Transient failure** (timeout, connection error, HTTP 5xx, HTTP 429):
    the batch's rows stay `pending`, their `attempts` count rises by one and
    `next_attempt_at` moves out with exponential backoff (5 min, 15 min,
    1 h, 4 h, then 12 h), honouring a `Retry-After` header when it is
    longer. After `max_attempts` (default 8) a row becomes `error` with code
    `gave_up`. The drain stops for this run on a 429 or a 5xx.
21. **Authentication failure** (HTTP 401 or 403): the drain pauses itself
    (state `paused_auth`), stops sending anything, and shows an admin notice
    to users who can manage the plugin. Rows stay `pending` and attempts do
    not rise. The pause clears when the settings that affect the adapter
    are saved, or by `wp post-embeddings resume`.
22. **Input too long** (HTTP 400 or 413 that the adapter classifies as
    input length): the plugin halves the character limit for those posts and
    tries once more in a batch of one. A second failure sets `error` with
    code `too_long`.
23. **Other HTTP 4xx:** the batch is retried once as single-post calls to
    find the bad input. Posts that fail alone become `error` with code
    `rejected_<status>`; the rest are stored.
24. **Malformed response** (not JSON, wrong shape): treated as transient
    (case 20), and the first 300 bytes of the body go to the log with any
    `Authorization` or key-like value removed.
25. A failed call that the provider may still bill (a timeout after the
    request was sent) is counted against the daily cap as if it succeeded.
    Under-counting is the dangerous direction.
26. `wp post-embeddings retry --errors` moves `error` rows back to `pending`
    with attempts reset. Nothing else retries `error` rows.

### 4.5 Caps and the kill switch

27. **Indexing daily cap.** Two limits per UTC day per site: posts embedded
    (`daily_post_cap`, default 500) and estimated input tokens
    (`daily_token_cap`, default 0, meaning off). A batch is trimmed so it
    does not cross either limit; once a limit is reached nothing more is
    sent until 00:00 UTC. The token estimate is the provider's reported
    usage when present, otherwise characters divided by 4, rounded up.
28. **Query daily cap.** Search queries that need a fresh embedding count
    against `daily_query_cap` (default 1,000) separately from indexing, so
    readers cannot starve the backfill and the backfill cannot break search.
    Cached queries (case 55) do not count.
29. Counters live in one option per site, keyed by UTC date, reset by date
    change rather than by a cron job, so a missed cron cannot leave a cap
    stuck.
30. **Kill switch.** The setting `enabled` (default on after the operator
    saves a provider) and the constant `POST_EMBEDDINGS_DISABLED` set to
    `true` in `wp-config.php`. When either says off: no provider call of any
    kind is made, not for indexing, backfill or search. Existing vectors and
    the sidecar keep serving related posts, because that costs nothing.
    Search falls back as in case 58. The constant wins over the setting, and
    the settings screen shows that it is set.
31. A separate setting `search_enabled` (default on) turns off only the
    search query embeddings.
32. **Backfill cap.** Every backfill run has a cap (case 63). The cap counts
    against the indexing daily cap too; the smaller of the two applies.
33. **spend-meter integration.** When the `spend_meter_pre_spend` filter
    has a callback, each provider call first asks it with purpose
    `post-embeddings.index` or `post-embeddings.query`, caller
    `post-embeddings`, the provider and model, and the estimated input
    tokens. A decision whose `allowed` is not exactly `true` means no call.
    After the call the plugin fires `spend_meter_record` with the
    reservation id, status and token usage; when the call is not made after
    a reservation, it fires `spend_meter_release`. When the filter returns
    `null` (no meter), the plugin's own caps alone apply. The plugin's caps
    and kill switch always apply, meter or not.
34. A cap or kill switch hit is recorded in the status screen and the log
    once per day per limit, not once per request.

### 4.6 The sidecar

35. A rebuild is queued as a single scheduled event 60 seconds ahead. If one
    is already queued, nothing new is scheduled (debounce). An hourly event
    also calls the library's rebuild-if-stale path with a maximum age of
    `sidecar_max_age` (default 3,600 s).
36. The rebuild streams index rows of the current model key with status
    `ok` whose posts are still eligible, by ascending post id, in pages of
    500 (keyset, never `OFFSET`), and hands them to the packed-vectors
    builder as records with id and ref both equal to the post id. Vectors
    are already unit length; the builder is run with normalisation on as a
    second guard.
37. The sidecar file name includes a short hash of the model key and the
    blog id, so a model change produces a new file and never a dimension
    mismatch inside one file. Files of other model keys are removed after a
    successful build of the current one.
38. The build report (written, skipped by reason, bytes, seconds) is stored
    in the plugin state and logged as one line. Any skipped count above zero
    is a warning.
39. If the sidecar directory is missing or not writable, the rebuild records
    the error in the status screen and the log, and requests keep using the
    fallback scan (case 43).
40. A second rebuild that finds the library's lock busy returns at once;
    the debounce event is re-queued once.

### 4.7 Related posts

41. For a post P, the plugin reads P's vector from the index row (one
    primary-key read). If P has no `ok` row for the current model key,
    related returns an empty list, and the block renders its fallback (case
    50).
42. It scans the sidecar for the top `count * overfetch` hits (overfetch
    default 3, at most 200 hits) with `minScore` from the settings (default
    0.30) and a time budget of 0.3 s.
43. If the scan throws the library's "unavailable" exception (missing,
    unreadable, corrupt, other version, dimension mismatch), the plugin
    uses the library's chunked scanner over the index table for this
    request, with the same options, filtered to the current model key, and
    queues one rebuild (case 35). If the chunked scan also fails or exceeds
    its budget of 1.5 s, related returns an empty list.
44. A scan that exceeds its time budget returns an empty list for this
    request and is logged (rate-limited, case 67). The result is not cached.
45. Hits are filtered in PHP after the scan: drop P itself, drop posts that
    are no longer eligible (one `WP_Query` by id list with `post__in`,
    `no_found_rows`, published status only), drop post types not allowed by
    the block's attribute, then keep the first `count`.
46. Order is by score, highest first. Equal scores are ordered by newer
    publish date first, then lower post id, so output is stable.
47. Near-identical posts: hits with a score at or above `duplicate_score`
    (default 0.98) against P are dropped, as are hits at or above that score
    against a hit already kept. This stops a reposted or syndicated copy
    filling the list. Setting it to 1.01 turns it off.
48. An optional `max_age_days` (default 0, off) drops hits published more
    than that many days before P.
49. Results for P are cached in the object cache (or a transient when there
    is no persistent object cache) keyed by blog id, P, model key, sidecar
    `built_at`, and a hash of the query arguments. A new sidecar build or a
    change to P's row naturally misses the old key. TTL 12 h.
50. **Fallback when there are no results:** the block attribute
    `fallback` is one of `none` (render nothing, default), `recent` (latest
    posts of the same post type) or `same_term` (latest posts sharing P's
    first category). The fallback is never cached as if it were semantic.
51. The related-posts block is dynamic and server-rendered. Attributes:
    `count` (1 to 12, default 4), `postTypes` (default the post's own type),
    `heading` (default "Related"), `showDate`, `showExcerpt`, `showImage`
    (default true, false, true), `fallback` (case 50), `minScore` (empty
    means the site setting). On a page that is not a single post the block
    renders nothing.
52. Markup: a `<section>` with the heading as `<h2>` and an `<ul>` of
    `<li>` items, each with a link, optional thumbnail (`thumbnail` size,
    with `loading="lazy"` and alt text from the attachment), optional date
    in a `<time>` element, optional excerpt. Every value is escaped. Class
    names follow the block's own namespace. The `post_embeddings_related_html`
    filter may replace the markup.
53. Readers never see scores. Scores appear only in the WP-CLI output and
    the REST response for users with the manage capability.

### 4.8 Semantic search

54. The search block renders a form (method GET, one text field, a submit
    button with a visible label). The query parameter is `semantic` by
    default, filterable, so it never collides with core's `s`. When the
    parameter is present on the page, the block renders results below the
    form.
55. Query handling: trim, collapse whitespace, NFC when available, reject
    under 3 or over 200 characters (render a short message, no call). The
    normalised lower-cased query is hashed; a cached vector for that hash
    and model key (object cache or transient, TTL 7 days) is used if
    present. Only the vector is cached, never the query text.
56. A query that needs a fresh embedding passes, in order: the kill switch
    and `search_enabled` (cases 30, 31), a per-client rate limit (default 10
    fresh embeddings per 10 minutes per client, keyed by a salted hash of
    the remote address), the query daily cap (case 28), and spend-meter
    (case 33). The provider call has a 5 s timeout and no retry.
57. Results: scan as in cases 42 to 46 with `minScore` from the
    `search_min_score` setting (default 0.25), keep the top `count` (block
    attribute, 1 to 20, default 10), render title, date and excerpt. No
    pagination in version 1; the block offers a "search by keyword" link to
    core search with the same text.
58. **Search fallback:** if the query cannot be embedded for any reason
    (kill switch, cap, rate limit, provider error, timeout), the block
    renders core keyword results for the same text (a `WP_Query` with `s`,
    limited to `count`), headed "Keyword results", and never an error. The
    reason code goes to the log only.
59. Results pages vary by query parameter. The plugin sends
    `Cache-Control: private, max-age=0` on a request that carries the
    parameter only when the setting `search_no_store` is on (default off),
    because many page caches already vary on the full URL. The README must
    say which is right for which cache.
60. Opt-in core search integration: when the filter
    `post_embeddings_replace_core_search` returns true, the main query on a
    search page is reordered by semantic score for the same text, with the
    same fallback (case 58). Off by default.

### 4.9 REST, shortcodes and functions

61. REST routes under `post-embeddings/v1`:
    - `GET /related/<id>?count=` public; returns `[{id, title, link,
      date}]` for a published post and 404 for anything else. Scores only
      for users with the manage capability.
    - `GET /search?q=&count=` public; same rules as cases 55 to 58,
      including the rate limit; on fallback returns keyword results with
      `"mode": "keyword"`, otherwise `"mode": "semantic"`.
    - `GET /status` manage capability only; counts, caps, sidecar report.
62. Shortcodes `[post_embeddings_related]` and `[post_embeddings_search]`
    take the same attributes as the blocks. PHP functions
    `post_embeddings_related( int $post_id, array $args = [] ): array` and
    `post_embeddings_search( string $query, array $args = [] ): array` return
    lists of `WP_Post` and never throw.

### 4.10 Backfill and WP-CLI

63. `wp post-embeddings backfill --cap=<n>` embeds eligible posts that have
    no `ok` row for the current model key or whose text hash changed.
    `--cap` is required; without it the command prints usage and exits 1. A
    cap above the `backfill_cap_ceiling` setting (default 5,000) is refused.
    Options: `--post-type=<a,b>`, `--order=newest|oldest` (default newest),
    `--since=<YYYY-MM-DD>`, `--batch=<n>` (1 to 64), `--dry-run`, `--yes`.
64. Backfill walks posts by id with a keyset cursor, stored in the plugin
    state after every batch, so an interrupted run continues where it
    stopped with `--resume`. `--dry-run` prints how many posts would be
    sent, the estimated tokens, and how much of today's cap is left, and
    calls nothing.
65. Backfill obeys the kill switch, the indexing daily cap and spend-meter
    between every batch, prints a line per batch (sent, stored, errors,
    cap left), and stops with exit code 0 and a clear message when a cap or
    the kill switch stops it. It queues one sidecar rebuild at the end.
    Ctrl-C between batches loses nothing.
66. Other commands: `status` (counts by status and model key, today's cap
    use, sidecar report, last drain), `drain` (run one drain now),
    `rebuild` (build the sidecar now and print the report), `verify`
    (check the sidecar against the table: count of `ok` eligible rows versus
    records, and the library's checksum check), `related <post_id>` (print
    hits with scores), `search "<text>"` (counts against the query cap),
    `retry --errors`, `resume`, `prune --model=<key>` (remove index rows of
    a model key that is not current, after a confirmation unless `--yes`).
    Every command returns a non-zero exit code on failure.

### 4.11 Observability and errors

67. Log lines go through `error_log` with the prefix `[post-embeddings]`
    when the `log` setting is on (default on), and through the action
    `post_embeddings_log` (level, code, context array) always. Repeated
    lines of the same code are limited to one per 10 minutes. Logs never
    contain post text, query text, API keys or full provider bodies.
68. Every exception from the adapter, the library or the database inside a
    request path is caught at the block, shortcode, function or REST
    boundary. The reader sees the fallback or nothing. The exception goes to
    the log.
69. The status screen (Tools, Post embeddings) shows: provider and model
    key, kill switch state and where it comes from, counts by status,
    today's indexing and query use against caps, pause state, sidecar path,
    size, record count, age and last report, last drain time and result,
    and buttons for "Run drain now", "Rebuild sidecar" and "Resume" (each a
    POST with a nonce and a capability check).
70. A Site Health test reports a problem when: the sidecar is older than
    three times `sidecar_max_age`; the drain has not run for an hour while
    rows are pending; the plugin is paused for authentication; the sidecar
    directory sits under the document root without a deny rule the plugin
    could verify (case 81).

### 4.12 Model changes

71. Changing the adapter, model or dimension changes the model key. Old
    rows stay in the table under their old key and are not used. Every
    eligible post becomes due for embedding under the new key; nothing is
    sent until the drain or a backfill picks them up within the caps.
72. Until a post has a row under the new key, related for that post
    returns the fallback, and it does not appear in other posts' results.
    The status screen shows progress as "n of m posts under the current
    model".
73. Vectors of different model keys are never compared. Any code path that
    finds two vectors of different lengths throws a dimension error rather
    than comparing the overlap.

### 4.13 Edge cases and failure modes summary

| Situation | What happens | Open or closed |
|---|---|---|
| Provider down during a save | Nothing: saves never call the provider | Open (save succeeds) |
| Provider down during drain | Rows stay pending, backoff (case 20) | Closed on spend |
| 401 or 403 | Pause, admin notice (case 21) | Closed on spend |
| Sidecar missing or corrupt | Chunked scan, one rebuild queued (case 43) | Open on reads |
| Dimension mismatch in sidecar | Same as missing | Open on reads |
| Scan over budget | Empty list, fallback renders (case 44) | Open |
| Cap reached | No calls; search uses keyword fallback | Closed on spend, open on reads |
| Kill switch on | No calls; related keeps serving | Closed on spend, open on reads |
| Corrupt index row (bad blob length) | Row skipped by the chunked scan and the builder, counted, marked `error` with `bad_stored_vector` at next drain | Open |
| Database table missing | Admin notice, blocks render fallback, activation repair offered | Open |
| Object cache flush | Caches miss and are rebuilt | Open |
| Post deleted after the sidecar was built | Filtered at request time (case 45) | Open |
| Multisite site deleted | Its table and files removed (case 80) | n/a |

## 5. Public interface

Namespace `IndianOpinion\PostEmbeddings`. Global functions and hooks use the
prefix `post_embeddings_`.

### 5.1 Provider adapter interface

`interface EmbeddingProvider`:

| Method | Returns | Meaning |
|---|---|---|
| `id(): string` | `^[a-z0-9_-]{1,32}$` | Adapter id, part of the model key |
| `model(): string` | up to 128 chars | Model name as sent to the provider |
| `dimensions(): int` | 1 to 65,535 | Length of every vector returned |
| `maxInputChars(): int` | at least 100 | Character limit per input |
| `maxBatch(): int` | 1 to 256 | Inputs per call |
| `embed(array $texts): EmbeddingResult` | | `$texts` is a list of non-empty strings, at most `maxBatch()` |

`EmbeddingResult` holds, by input index, a vector (list of floats) or a
per-input error code, plus `inputTokens` (int or null) and the HTTP status.

Typed exceptions, all implementing `ProviderError`:
`TransientProviderError` (with optional `retryAfter` seconds),
`AuthProviderError`, `InputTooLongError`, `RejectedInputError` (with HTTP
status), `MalformedResponseError`. An adapter must throw one of these and
nothing else for provider problems.

### 5.2 Reference adapter: HTTP embeddings

`HttpEmbeddingProvider` speaks the common embeddings request shape:
`POST <base_url>/embeddings` with JSON `{"model": ..., "input": [...],
"dimensions": <n>}` (the `dimensions` key is sent only when the setting
`send_dimensions` is on) and a bearer token in `Authorization`. It reads
`data[].embedding` mapped by `data[].index`, and `usage.prompt_tokens` when
present. It uses `wp_remote_post` with a timeout of 30 s for indexing and
5 s for search, `sslverify` on, and never logs the key.

Settings: `base_url` (must be `https`, except `http://localhost` and
`http://127.0.0.1` for self-hosted models), `model`, `dimensions`,
`api_key` (or the constant `POST_EMBEDDINGS_API_KEY`, which wins and is
never shown in the screen), `max_input_chars`, `max_batch`,
`send_dimensions`, and `extra_headers` (filter only, not a setting).

A `FakeProvider` for tests and local development returns deterministic
unit vectors derived from a hash of the text. It is never offered in the
settings screen; it is enabled only through the provider filter. The
README says plainly that its results are not semantic.

### 5.3 Filters

| Hook | Arguments | Default and purpose |
|---|---|---|
| `post_embeddings_provider` | `?EmbeddingProvider $p, array $settings` | Return any adapter. Default: the HTTP adapter when configured, else `null` (plugin idle) |
| `post_embeddings_is_eligible` | `bool $ok, WP_Post $post` | Exclude posts |
| `post_embeddings_text` | `string $text, WP_Post $post` | Replace the embedding text |
| `post_embeddings_post_types` | `array $types` | Default from settings |
| `post_embeddings_related_args` | `array $args, int $post_id` | Change count, scores, filters |
| `post_embeddings_related_results` | `array $posts, int $post_id, array $args` | Reorder or trim |
| `post_embeddings_related_html` | `string $html, array $posts, array $attrs` | Replace markup |
| `post_embeddings_search_results` | `array $posts, string $query, string $mode` | Reorder or trim |
| `post_embeddings_search_param` | `string $name` | Default `semantic` |
| `post_embeddings_replace_core_search` | `bool $on` | Default false (case 60) |
| `post_embeddings_sidecar_dir` | `string $dir` | Sidecar directory (case 81) |
| `post_embeddings_rate_limit` | `array{limit:int,window:int} $rule` | Default 10 per 600 s |
| `post_embeddings_capability` | `string $cap` | Default `manage_options` |
| `post_embeddings_default_settings` | `array $defaults` | Change any default in section 7 |

### 5.4 Actions

| Hook | Arguments | When |
|---|---|---|
| `post_embeddings_stored` | `int $post_id, string $model_key` | A vector was stored |
| `post_embeddings_failed` | `int $post_id, string $code` | A row became `error` |
| `post_embeddings_sidecar_built` | `array $report` | After every build |
| `post_embeddings_cap_reached` | `string $which, int $limit` | Once per limit per UTC day |
| `post_embeddings_paused` | `string $reason` | Auth pause began |
| `post_embeddings_log` | `string $level, string $code, array $context` | Every log event |

### 5.5 Blocks, shortcodes, REST, CLI

- Blocks: `post-embeddings/related`, `post-embeddings/search`. Both
  dynamic, registered from `block.json`, with editor previews that call the
  REST routes. The editor preview of search never spends: it renders a
  placeholder.
- Shortcodes, REST routes and functions: cases 61 and 62.
- WP-CLI: `wp post-embeddings <backfill|drain|status|rebuild|verify|related|search|retry|resume|prune|kill>`;
  `kill on|off` flips the setting (it cannot override the constant).

## 6. Data

- **Index table** `{prefix}post_embeddings` (per site in multisite):
  `post_id` (bigint unsigned), `model_key` (varchar 191), primary key on
  both; `status` (`pending`, `ok`, `empty`, `ineligible`, `error`);
  `dim` (smallint unsigned); `vector` (longblob, float32 little-endian,
  exactly `4 * dim` bytes, or null); `text_hash` (char 64); `tokens` (int,
  null); `attempts` (tinyint); `error_code` (varchar 64, null);
  `next_attempt_at`, `embedded_at`, `updated_at` (datetime UTC). Indexes on
  (`model_key`, `status`, `post_id`) for keyset scans and on
  (`status`, `next_attempt_at`) for the drain.
- Created by `dbDelta` on activation and on version upgrade. After
  `dbDelta`, the plugin verifies every column with `SHOW COLUMNS` and shows
  an admin notice naming any missing one, because `dbDelta` can skip a
  column add without an error.
- **Options** (autoload off except the settings): `post_embeddings_settings`,
  `post_embeddings_state` (pause, cursors, last reports),
  `post_embeddings_counters` (UTC-dated cap counters),
  `post_embeddings_db_version`, `post_embeddings_drain_lock`.
- **Sidecar files** in the sidecar directory: one `.vec` file per site and
  model key, its `.lock` file, and any temporary files from a crashed build.
- **Caches:** related results (12 h), query vectors (7 days), rate-limit
  counters (their window). All in the object cache or transients.
- **Retention:** rows live as long as the post. Rows of old model keys stay
  until `prune`. Nothing stores query text.
- **Uninstall** (`uninstall.php`): drops the table, deletes every option
  and transient with the prefix, removes the sidecar files, lock files and
  temporary files the plugin created, and unschedules its events. In
  multisite it does this for every site. Deactivation only unschedules
  events.

## 7. Configuration and sensible defaults

| Setting | Default | Notes |
|---|---|---|
| `enabled` | true once a provider is saved | Kill switch; constant `POST_EMBEDDINGS_DISABLED` wins |
| `search_enabled` | true | Search query embeddings only |
| `post_types` | `post` | List |
| `min_chars` | 40 | Case 11 |
| `batch_size` | 16 | Clamped to the adapter's `maxBatch()` |
| `drain_interval` | 300 s | 60 to 3,600 |
| `drain_max_batches` | 4 | Per run |
| `max_attempts` | 8 | Case 20 |
| `daily_post_cap` | 500 | Posts per UTC day, indexing |
| `daily_token_cap` | 0 (off) | Estimated tokens per UTC day, indexing |
| `daily_query_cap` | 1,000 | Fresh query embeddings per UTC day |
| `backfill_cap_ceiling` | 5,000 | Largest `--cap` allowed |
| `min_score` | 0.30 | Related |
| `search_min_score` | 0.25 | Search |
| `duplicate_score` | 0.98 | Case 47 |
| `overfetch` | 3 | Case 42 |
| `max_age_days` | 0 (off) | Case 48 |
| `sidecar_max_age` | 3,600 s | Case 35 |
| `search_no_store` | false | Case 59 |
| `log` | true | Case 67 |

Score thresholds depend on the model. The README must say so and suggest
checking `wp post-embeddings related <id>` on a few posts before changing
them. Every default passes through `post_embeddings_default_settings`.
Constants override settings: `POST_EMBEDDINGS_DISABLED`,
`POST_EMBEDDINGS_API_KEY`, `POST_EMBEDDINGS_SIDECAR_DIR`.

## 8. Security and privacy

74. Post text leaves the site for the provider. Only eligible posts are
    sent: published, not password-protected, not private. The settings
    screen says this next to the provider fields, and the README repeats it.
75. Search queries leave the site for the provider. The plugin never stores
    or logs query text; it caches only the vector under a hash. The README
    suggests a privacy-policy line, offered through
    `wp_add_privacy_policy_content`.
76. The API key is stored in the settings option only if no constant is
    defined, is never printed back (the field shows "saved" and an empty
    input), and is never sent anywhere but the configured base URL.
77. Every admin action and settings save checks the capability (filter,
    default `manage_options`) and a nonce. REST routes that write or show
    scores require the capability; the public routes are read-only and
    rate-limited.
78. Every output in blocks, shortcodes and the admin screen is escaped.
    Every input is validated: post ids as positive integers, counts clamped,
    query length bounded, post types checked against registered public
    types, `base_url` checked for scheme and host.
79. The rate limit key is a salted hash of the remote address
    (`REMOTE_ADDR` only; proxy headers are trusted only through a filter the
    site opts into), so the plugin stores no raw IP addresses.
80. In multisite, each site's data, caps and sidecar are separate. Deleting
    a site (`wp_uninitialize_site`) removes that site's table, options and
    sidecar files.
81. **Sidecar location.** Vectors can leak information about the text they
    came from. The default directory is the first writable of: the
    constant, the filter, then `wp-content/uploads/post-embeddings-private/`
    as a last resort with an `index.php`, a deny-all `.htaccess` and a
    `web.config`. On activation and in Site Health the plugin requests the
    sidecar file's URL over HTTP from the site itself; if that returns
    200, Site Health reports a critical problem and the README shows the
    nginx rule to block it. The README recommends a directory outside the
    document root.
82. No `eval`, no shell, no `@` error suppression, no unserialize of
    stored data (the vector blob is unpacked with `unpack`).

## 9. Performance budget

| Measure | Budget |
|---|---|
| `save_post` overhead | under 5 ms, one primary-key read and at most one write; no network |
| Related block, cache hit | under 2 ms |
| Related block, cache miss, 10,000 posts at 512 dimensions | under 1.0 s including the scan; scan time budget 0.3 s on a typical host |
| Chunked fallback scan | 1.5 s budget, memory of one page of 400 rows plus the top-k |
| Search, cached query vector | same as related miss |
| Search, fresh query vector | provider latency plus the scan; provider timeout 5 s |
| Drain run | under 25 s wall time, peak memory under 32 MiB added |
| Sidecar build, 100,000 posts | streamed in pages of 500, under 16 MiB added |
| Admin status screen | under 300 ms; counts by indexed `GROUP BY` |

The packed-vectors library publishes its own numbers: about 0.84 s for a
10,000 x 512 scan with the checksum on, and about 8 MiB of added memory. The
plugin's budgets build on those. Sites near 100,000 posts should lower
`overfetch` or raise the time budget through a filter; the README says so.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3, 64-bit only (packed-vectors refuses 32-bit). The plugin
  shows an admin notice and stays idle on a 32-bit build.
- WordPress 6.4 or later. Block editor for the blocks; shortcodes and
  functions work with the classic editor and in classic themes.
- Runtime dependency: `indianopinion/packed-vectors` only, declared in
  `composer.json` as a `vcs` repository. A release zip bundles it under
  `vendor/` with its licence. The plugin loads either the Composer
  autoloader or the library's bootstrap file. It does not depend on
  `indianopinion/llm-json-php`, which handles chat completions and not
  embeddings; provider calls go through the WordPress HTTP API.
- Must-use plugin: a loader stub in `mu-plugins/` that requires the main
  file works; activation hooks do not fire there, so the table is created
  on the first admin request when `post_embeddings_db_version` is missing.
- Page caches: related output is part of the cached page and changes only
  when the page cache is purged; the README says to expect related lists
  to update on the next page cache expiry. Search pages vary on the URL
  query string (case 59).
- Object caches: works with and without a persistent object cache.
- Filesystems: the sidecar needs local disk with atomic rename; on network
  filesystems the README says to use the chunked fallback only (a filter
  turns the sidecar off).
- Optional `intl` for NFC normalisation; without it the text is used as is.
- Optional `spend-meter` (case 33), detected at call time through its
  filter, never required.

## 11. Observability

- Log lines (case 67) with codes, for example `drain_ok`, `drain_partial`,
  `provider_transient`, `provider_auth`, `bad_vector_length`,
  `cap_reached_posts`, `cap_reached_queries`, `kill_switch`,
  `sidecar_built`, `sidecar_skipped`, `sidecar_unavailable`,
  `scan_budget`, `search_fallback`. Each line carries counts and ids, never
  text.
- Status screen and `wp post-embeddings status` (case 69).
- `verify` (case 66) for a deep check.
- Site Health tests (case 70).
- Actions in section 5.4 for sites that ship metrics elsewhere.

## 12. Acceptance tests

Each test names the cases it covers. Tests run under PHPUnit with a stub
layer for WordPress and a fake provider unless they say otherwise.

| # | Covers | Test |
|---|---|---|
| T1 | 1 | Saving an eligible post creates a `pending` row; saving again with the same text and an `ok` row changes nothing and makes no call |
| T2 | 2 | An autosave, a revision and an auto-draft create no row |
| T3 | 3 | Unpublishing marks the row `ineligible`; republishing unchanged returns it to `ok` with no provider call |
| T4 | 4 | Deleting a post removes its row and queues one rebuild |
| T5 | 5 | Removing a post type from settings marks its rows `ineligible` at the next drain; adding one embeds nothing until a backfill |
| T6 | 6 | A future post gets no row until it publishes |
| T7 | 7 | Text is title, manual excerpt and cleaned content; shortcodes, block comments and tags are gone; no `the_content` filter runs; the text filter can replace it |
| T8 | 8 | A Devanagari title and Tamil content survive normalisation unchanged apart from NFC |
| T9 | 9 | A 20,000-character multibyte post is cut to the limit at a whitespace boundary without a broken character |
| T10 | 10, 11 | An empty post and a 20-character post become `empty` with no call |
| T11 | 12 | The drain sends pending posts oldest first in one batch of at most `batch_size` |
| T12 | 13 | With the kill switch on, the cap reached, or spend-meter denying, the drain sends nothing and rows stay `pending` |
| T13 | 14 | The drain stops after `drain_max_batches` or the wall-time limit (clock injected) |
| T14 | 15 | Two drains started together send each post once; a lock past its expiry is taken over |
| T15 | 16 | Vectors with a wrong length, a NaN, a string value or zero norm are not stored and the rows get the matching `bad_vector_` code; a length error logs a warning |
| T16 | 17 | A stored vector unpacks to unit length within 1e-6 and carries model key, dim, hash and time |
| T17 | 18 | A response with shuffled indexes maps correctly; a missing index sets `missing_in_response` |
| T18 | 19 | A drain that stores a vector queues exactly one rebuild |
| T19 | 20 | A 429 with `Retry-After: 7200` sets `next_attempt_at` two hours out; after `max_attempts` the row becomes `gave_up` |
| T20 | 21 | A 401 pauses the drain, shows a notice, does not raise attempts; saving settings clears the pause |
| T21 | 22 | An input-too-long error retries once at half the limit, then `too_long` |
| T22 | 23 | A 400 on a batch of 3 retries singly; only the bad post becomes `rejected_400` |
| T23 | 24 | A non-JSON body is transient and the logged excerpt contains no key |
| T24 | 25 | A timeout after send counts against the cap |
| T25 | 26 | `retry --errors` moves error rows to pending with attempts reset; nothing else touches them |
| T26 | 27 | With 495 of 500 used, a batch of 16 is trimmed to 5; with a token cap the estimate trims too |
| T27 | 28 | Search embeddings count only against the query cap; a cached query counts against nothing |
| T28 | 29 | Counters reset when the UTC date changes, with no cron run |
| T29 | 30 | With the constant set, no call of any kind happens, related still renders from the sidecar, the screen shows the constant |
| T30 | 31 | `search_enabled` off stops query embeddings and leaves indexing running |
| T31 | 32 | A backfill with `--cap=100` and 40 left in the daily cap sends 40 |
| T32 | 33 | With a stub meter: a deny means no call; an allow is followed by exactly one record; an aborted call releases; a null result falls back to own caps |
| T33 | 34 | Hitting a cap 50 times logs once and fires the cap action once that day |
| T34 | 35 | Ten change events within a minute schedule one rebuild; the hourly check rebuilds a stale file |
| T35 | 36 | The rebuild streams pages of 500 by keyset and writes only current-model `ok` eligible rows |
| T36 | 37 | Changing the model writes a new file name and removes the old file after success |
| T37 | 38 | A build with skipped rows logs a warning with counts |
| T38 | 39 | An unwritable directory records an error and requests use the chunked scan |
| T39 | 40 | A rebuild finding the lock busy returns at once and re-queues once |
| T40 | 41 | A post with no current-model row gets an empty list and the block renders its fallback |
| T41 | 42 | The scan asks for `count * overfetch` hits, capped at 200, with the configured score and budget |
| T42 | 43 | With the sidecar missing, corrupt or of another dimension, results come from the chunked scan, match the sidecar ranking, and one rebuild is queued |
| T43 | 44 | A scan over budget returns empty, logs once, caches nothing |
| T44 | 45 | The post itself, a trashed post and a disallowed type are filtered out |
| T45 | 46 | Equal scores order by newer date then lower id |
| T46 | 47 | A copy scoring 0.99 against P, and a pair of near-identical hits, leave one entry |
| T47 | 48 | `max_age_days` drops older hits |
| T48 | 49 | A second render hits the cache; a new sidecar `built_at` misses it |
| T49 | 50 | Each fallback mode renders the right posts and is not stored in the related cache |
| T50 | 51 | Attributes are clamped; the block renders nothing off a single post |
| T51 | 52 | Markup escapes a title containing `<script>`; images are lazy with alt text; the HTML filter replaces markup |
| T52 | 53 | Rendered HTML and public REST output contain no scores; the manage-capability REST output does |
| T53 | 54 | The form uses the `semantic` parameter and a labelled submit button |
| T54 | 55 | A 2-character and a 201-character query make no call; the same query twice calls once; no query text is stored anywhere |
| T55 | 56 | The 11th fresh query from one client in 10 minutes falls back; the stored key is not the raw address |
| T56 | 57 | Results respect `search_min_score` and `count` and include the keyword link |
| T57 | 58 | Each of kill switch, cap, rate limit, provider error and timeout renders keyword results headed "Keyword results" |
| T58 | 59 | `search_no_store` adds the private header only on requests with the parameter |
| T59 | 60 | Core search is untouched by default and reordered when the filter returns true |
| T60 | 61 | The REST routes return the documented shapes, 404 for a draft, `mode` on search, 403 on status without capability |
| T61 | 62 | Shortcodes match block output; the functions never throw when the provider throws |
| T62 | 63 | Backfill without `--cap` exits 1; a cap above the ceiling is refused |
| T63 | 64 | An interrupted backfill resumes from the stored cursor; `--dry-run` calls nothing and prints estimates |
| T64 | 65 | Backfill stops cleanly at the cap with exit 0 and queues one rebuild |
| T65 | 66 | Each CLI command runs against the stubs; `verify` reports a count mismatch; `prune` refuses the current key |
| T66 | 67 | Logs carry no post text, query or key; repeated codes are rate-limited; the log action always fires |
| T67 | 68 | An exception from the adapter, the scanner or `$wpdb` inside a render produces fallback output and a log line |
| T68 | 69 | The status screen shows each listed value; its buttons reject a bad nonce and a user without the capability |
| T69 | 70 | Each Site Health condition is reported |
| T70 | 71, 72 | After a model change old rows stay, all posts become due, related falls back until re-embedded, progress shows n of m |
| T71 | 73 | Comparing vectors of different lengths throws; no code path compares a prefix |
| T72 | 74 | A private and a password-protected post are never sent |
| T73 | 75 | Privacy policy text is registered; no option, transient or log holds query text |
| T74 | 76 | The key field renders empty; a defined constant wins and is never shown |
| T75 | 77 | Every admin action and write route checks capability and nonce |
| T76 | 78 | Out-of-range counts are clamped; an unknown post type is ignored; an `http` base URL other than localhost is refused |
| T77 | 79 | A forwarded-for header is ignored unless the opt-in filter is set |
| T78 | 80 | Deleting a site in multisite removes its table, options and files only |
| T79 | 81 | A sidecar URL that answers 200 is reported as critical; the deny files are written |
| T80 | 82 | A static check finds no `eval`, shell call, `@` suppression or `unserialize` |
| T81 | section 6 | `dbDelta` with a missing column triggers the `SHOW COLUMNS` notice; uninstall removes table, options, transients, files and events |
| T82 | section 9 | A memory test: drain and build with 10,000 stub posts stay inside their memory budgets |

## 13. Open questions

To be answered by the builder in `docs/ARCHITECTURE.md`.

1. **Default sidecar directory.** The fallback under `uploads/` with deny
   files is a compromise. Is there a safer default that works on common
   hosts, for example a directory beside `wp-content` when it is writable
   and outside the document root?
2. **Vector storage in the table.** This PRD proposes a float32 blob for
   space and speed. Confirm that `dbDelta` and common database hosts handle
   `longblob` cleanly, and record whether `wpdb` needs any care with binary
   values in prepared statements.
3. **Token estimate without reported usage.** Characters divided by 4
   undercounts for many non-Latin scripts. Should the estimate use bytes
   divided by 3 instead, or a per-script factor?
4. **Related cache invalidation.** Keying on the sidecar `built_at` means
   every rebuild invalidates every related list. Is that acceptable at
   100,000 posts, or should the key use the post's own row time plus a
   coarser sidecar generation?
5. **Long posts.** Truncation keeps the head of a post. Should a later
   version average several chunk vectors instead, and would that change the
   table shape?
6. **Search result pagination.** Version 1 shows one page. Is a "more
   results" link with an offset into the cached top hits worth adding?
7. **Second reference adapter.** Is the HTTP adapter with a `localhost`
   base URL enough for self-hosted models, or should a separate adapter for
   a local inference server's own request shape ship too?

## 14. Lessons from production (the traps, as rules)

1. **Never compare vectors of different lengths.** A cosine that loops to
   the shorter length returns a believable number for vectors from two
   different models. Check the length, refuse, and log.
2. **Record the model with every vector.** Without a model key on each row
   a model change mixes two vector spaces in one comparison, silently.
3. **A zero vector has no direction.** Treat a zero norm as "no vector",
   never as a score of 0 that still ranks.
4. **Do not keep vectors as JSON for scanning.** Decoding JSON per row per
   request is what ran past 10 s and out of memory. Keep the table as the
   source of truth and scan a packed file.
5. **A stored vector that will not decode is skipped, not fatal.** One bad
   row must never take a page down.
6. **Never fetch every vector in one result set.** Page with a keyset
   cursor; the driver and the decoded array each hold a full copy.
7. **Use a threshold and an over-fetch, not "top k whatever the score".**
   A best match below the bar is not a match. Filter after the scan, so
   over-fetch to leave room.
8. **Never call the provider in a reader's request for indexing, or in a
   save.** Queue it. A slow provider must not slow the editor or the site.
9. **Caps reset by date, not by cron.** A missed cron must not leave a cap
   stuck at "reached".
10. **Count a possibly billed failure as spent.** When in doubt about the
    budget, err towards stopping.
11. **Do not assume ASCII anywhere in text handling.** A split on
    non-ASCII characters turns a title in another script into nothing, and
    empty inputs then look identical to each other. Keep Unicode letters,
    marks and numbers.
12. **Verify schema changes.** `dbDelta` has skipped column adds without
    an error. Check with `SHOW COLUMNS` after every upgrade.
13. **A stale sidecar beats a slow request.** Keep serving the last good
    file while a rebuild is due; queue one rebuild, never one per request.
