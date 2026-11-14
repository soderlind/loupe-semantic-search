# Architecture diagrams

The diagrams in [../architecture.md](../architecture.md) are rendered with
[PR Lens](https://www.npmjs.com/package/@coldtea/pr-lens-renderer) from one graph document.
They show **current behavior (0.1.1)**. Every element is `unchanged`, and the provenance
pins `main` at `4cd8284` (the 0.1.1 code the architecture document describes).

| File | Purpose |
| --- | --- |
| [build-graph.mjs](build-graph.mjs) | Source of truth: lanes, nodes, edges, flows and views |
| [graph.json](graph.json) | Generated PR Lens graph document |
| [render.mjs](render.mjs) | Validates the graph and renders each view in light and dark |
| `img/*.svg` | Canonical diagrams (self-contained, animated flows) |
| `img/*.png` | Static copies of the SVGs |

## Regenerate

From the plugin root (the PR Lens packages are dev dependencies in `package.json`):

```sh
npm install
cd docs/diagrams
node build-graph.mjs
rm -rf img && node render.mjs graph.json img
for s in img/*.svg; do rsvg-convert -o "${s%.svg}.png" "$s"; done
```

`render.mjs` refuses to overwrite SVGs, so remove `img/` first. `rsvg-convert` comes from
librsvg (`brew install librsvg`). Message `note`s are kept in the graph but the renderer does
not draw them, so put anything a reader must see into the label.

## Source map

Section names refer to [../architecture.md](../architecture.md).

| View | Shows | Evidence |
| --- | --- | --- |
| `system-context` | Callers, Loupe Search, the add-on's entry points and boundary, the `ai` plugin, the provider (replaces the former Mermaid chart) | System context; Components; Indexing; Model change |
| `retrieval-components` | How `Hybrid_Search`, `Abilities` and the CLI reach `Ai_Adapter` through `Retriever` | Components; Dependency rules |
| `hybrid-search-view` | Front-end search, from `/?s=` to fused hits | Front-end search (`/?s=`) and `loupe-search/search` |
| `hybrid-fallback-view` | The semantic side fails; keyword hits pass through | Failure behavior |
| `rag-retrieve-view` | `loupe-semantic/retrieve` returning public passages | Ability `loupe-semantic/retrieve` (RAG); Invariants |

| Element | Evidence |
| --- | --- |
| `loupe-engine`, `loupe-rest`, `loupe-rules` | Loupe Search hooks and helpers named in System context and the REST flow (Loupe Search code, not this repository) |
| `hybrid` | `includes/Hybrid_Search.php` (`filter_order_results`, `filter_advanced_hits`, `merge`), `includes/Fusion.php` |
| `abilities` | `includes/Abilities.php` (`execute_retrieve`, `execute_search`) |
| `admin-ui` | `includes/Admin/Settings_Page.php`, `includes/CLI/Command.php` |
| `retriever` | `includes/Retriever.php` (`search`, `passages`, `public_only`) |
| `embedder` | `includes/Query_Embedder.php` (30 min cache, 1.5 s timeout), `includes/Rate_Limiter.php` |
| `store` | `includes/Ai_Repository_Vector_Store.php`, `includes/Vector_Store.php` |
| `passages` | `includes/Passage_Builder.php` (chunk text or `stale` excerpt) |
| `indexability` | `includes/Indexability.php` (`wpai_embedding_sync_is_indexable`) |
| `adapter` | `includes/Ai_Adapter.php`; the "boundary" badge is the dependency rule that only it calls the `ai` plugin |
| `ai-sync`, `ai-repo` | The `ai` plugin's `Embedding_Sync` and embedding repository, as used by `Ai_Adapter` |
| `provider` | Embedding provider reached through the AI Client and Connectors |
| Edge `engine-hybrid`, `rest-hybrid` | `loupe_search_order_results`, `loupe_search_advanced_hits` (Front-end and REST flows) |
| Edge `abilities-engine`, `abilities-hybrid` | `loupe-semantic/search` runs Loupe's engine, then `Hybrid_Search::apply()` |
| Edge `retriever-rules` | `Retriever::search()` checks `loupe_search_is_post_indexable()` and, with `public_only`, `WP_Loupe_Utils::is_publicly_viewable_post()` |
| Edge `adapter-provider` | Query embeddings and the model list (Settings tab model list) |
| Flow step `fuse` | `Fusion::rrf()`; with a `-term` exclusion semantic matches only rerank (Front-end flow, step 5) |
| Flow step `error` | `WP_Error` codes in Failure behavior |
| Flow step `check` | Invariant "Abilities and CLI passages return only publicly viewable posts" |

Not drawn: the REST flow (same shape as the front-end flow plus `matches_filter`), indexing
and backfill sequences (owned by the `ai` plugin), and the model-change sequence.
