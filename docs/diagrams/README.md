# Architecture diagrams

The diagrams in [../architecture.md](../architecture.md) are rendered with
[PR Lens](https://www.npmjs.com/package/@coldtea/pr-lens-renderer) from one graph document.
They show **current behavior (0.1.1)**. Every element is `unchanged`, and the provenance
pins `main` at `4cd8284` (the 0.1.1 code the architecture document describes).

| File | Purpose |
| --- | --- |
| [build-graph.mjs](build-graph.mjs) | Source of truth: lanes, nodes, edges, flows and views |
| [graph.json](graph.json) | Generated PR Lens graph document |
| [render.mjs](render.mjs) | Validates the graph and renders each view in light and dark, with the palette overrides applied |
| [palette.mjs](palette.mjs) | Colour overrides for the renderer palette, chosen to meet WCAG 2.2 contrast |
| [check-contrast.mjs](check-contrast.mjs) | Prints the contrast of each colour pair; exits 1 if a required pair fails |
| `img/*.svg` | Canonical diagrams (self-contained, animated flows) |
| `img/*.png` | Static copies of the SVGs |

## Regenerate

From the plugin root (the PR Lens packages are dev dependencies in `package.json`):

```sh
npm install
cd docs/diagrams
node check-contrast.mjs
node build-graph.mjs
rm -rf img && node render.mjs graph.json img
for s in img/*.svg; do rsvg-convert -o "${s%.svg}.png" "$s"; done
```

`render.mjs` refuses to overwrite SVGs, so remove `img/` first. `rsvg-convert` comes from
librsvg (`brew install librsvg`). Message `note`s are kept in the graph but the renderer does
not draw them, so put anything a reader must see into the label.

## Colour and contrast

The renderer has no palette option, so `palette.mjs` swaps colours in each SVG after rendering.
Its defaults fail three checks, and the overrides fix them:

| Pair | Requirement | Light | Dark |
| --- | --- | --- | --- |
| Muted subtitle on faded (0.82) sequence participant | 4.5:1 (1.4.3) | 4.09 → 4.80 (`#4d5560`) | 4.18 → 4.97 (`#a0a8b1`) |
| Edge arrows on background | 3:1 (1.4.11) | 2.78 → 3.42 (`#7d8590`) | 4.12, unchanged |
| Sequence lifelines on background | 3:1 (1.4.11) | 1.31 → 3.09 (`#848d97`) | 1.55 → 3.16 (`#5c646e`) |

All other text is between 5.2:1 and 15.8:1. Card borders stay light (1.3:1 and 1.9:1). They are
decorative: each card is identified by its label, icon and shadow. After a renderer upgrade, run
`node check-contrast.mjs` and confirm the new SVGs no longer contain the default colours, because
the overrides replace exact colour values.

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
