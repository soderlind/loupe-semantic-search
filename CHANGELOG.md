# Changelog

All notable changes to this project are documented in this file.

## [0.1.0] - 2026-11-13

### Added

- Hybrid (keyword + semantic) search for the front-end search, the REST search endpoint and the
  `loupe-search/search` ability, using reciprocal rank fusion.
- Abilities `loupe-semantic/retrieve` (RAG passages with sources) and `loupe-semantic/search`.
- Embeddings through the AI plugin and a provider connected under Settings → Connectors.
- A *Semantic search* tab in Settings → Loupe Search with model picker, hybrid options, rate limit, daily cap and index status.
- WP-CLI command `wp loupe-semantic` (`status`, `query`, `backfill`).
