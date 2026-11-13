# Changelog

All notable changes to this project are documented in this file.

## [0.1.1] - 2026-11-13

### Fixed

- The *Semantic search* tab opened slowly because the embedding model list was fetched from the provider
  on every page load (the AI client only caches it in the object cache). The list is now saved in a
  transient for 12 hours, keyed by the active connectors and whether each has credentials. Empty lists are not saved.

### Added

- *Refresh model list* link below the model picker.

## [0.1.0] - 2026-11-13

### Added

- Hybrid (keyword + semantic) search for the front-end search, the REST search endpoint and the
  `loupe-search/search` ability, using reciprocal rank fusion.
- Abilities `loupe-semantic/retrieve` (RAG passages with sources) and `loupe-semantic/search`.
- Embeddings through the AI plugin and a provider connected under Settings → Connectors.
- A *Semantic search* tab in Settings → Loupe Search with model picker, hybrid options, rate limit, daily cap and index status.
- WP-CLI command `wp loupe-semantic` (`status`, `query`, `backfill`).
