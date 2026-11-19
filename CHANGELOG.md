# Changelog

All notable changes are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-11-19

### Added

- One embedding per eligible post, computed in the background by a capped, locked, time-limited drain. Saves never call the provider.
- Index table with model key, dimension, text hash and a float32 vector blob; `SHOW COLUMNS` verification after `dbDelta`.
- Packed sidecar built with `indianopinion/packed-vectors` in a protected directory under `wp-content`, with debounced and hourly rebuilds and a chunked fallback scan over the table.
- Related posts block, shortcode, PHP function and REST route, with near-duplicate removal, age filter and three fallbacks.
- Semantic search block, shortcode, PHP function and REST route, with a cached query vector, per-client rate limit and keyword fallback.
- Opt-in reordering of core search results.
- WP-CLI: `backfill` (required cap, resumable, dry run), `drain`, `status`, `rebuild`, `verify`, `related`, `search`, `retry`, `resume`, `prune`, `kill`.
- Daily post, token and query caps counted atomically, so racing processes cannot overshoot them; kill switch setting and constant; optional spend-meter integration (contract 1: intent array, one record per attempt, release by reservation id).
- Backfill and drain share one lock; the backfill run cap counts every post sent, billed or not.
- Adapter URL guard: no private or reserved targets, remote calls through the WordPress safe-URL check, and a stored key cleared when the host changes.
- Reference HTTP adapter for the common embeddings request shape, and a deterministic fake adapter for tests.
- Status screen under Tools, Site Health tests, privacy-policy text, uninstall and multisite site cleanup.

[Unreleased]: https://github.com/IndianOpinion/post-embeddings/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/post-embeddings/releases/tag/v0.1.0
