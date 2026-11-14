# Architecture diagrams

The diagrams in [../architecture.md](../architecture.md) are rendered with
[PR Lens](https://www.npmjs.com/package/@coldtea/pr-lens-renderer) from one graph document.
They show **current behavior (0.1.1)**. Every element is `unchanged`, and the provenance
pins `main` at `4cd8284` (the 0.1.1 code the architecture document describes).

| File | Purpose |
| --- | --- |
| [build-graph.mjs](build-graph.mjs) | Source of truth: lanes, nodes, edges, flows and views |
| [graph.json](graph.json) | Generated PR Lens graph document |
| [render.mjs](render.mjs) | Validates the graph and renders each view in light and dark, with the GitHub theme colours applied |
| [palette.mjs](palette.mjs) | GitHub Light/Dark Default colours applied to the rendered SVGs, chosen to meet WCAG 2.2 contrast |
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

The renderer draws in greys and has no palette option, so `palette.mjs` recolours each SVG after
rendering. The colours come from the VS Code themes GitHub Light Default and GitHub Dark Default
([primer/github-vscode-theme](https://github.com/primer/github-vscode-theme), `@primer/primitives`
7.10.0). Each diagram element takes the colour of a matching syntax role:

| Element | Syntax role | Light | Dark | Lowest ratio (light / dark) |
| --- | --- | --- | --- | --- |
| Node title | function name | `#6639ba` | `#d2a8ff` | 4.91 / 6.03 (faded card) |
| Node subtitle | constant | `#0550ae` | `#79c0ff` | 5.09 / 6.04 (faded card) |
| Edge label | string | `#0a3069` | `#a5d6ff` | 12.81 / 12.31 |
| Lane label | keyword | `#cf222e` | `#ff7b72` | 4.91 / 7.06 |
| "boundary" badge | keyword, one step stronger | `#a40e26` | `#ffa198` | 5.32 / 5.16 (faded chip) |
| Node icon | variable | `#953800` | `#ffa657` | 4.59 / 5.17 (faded chip, 3:1 required) |
| Edges, arrowheads, flow dots | link accent | `#0969da` | `#58a6ff` | 4.76 / 7.05 (3:1 required) |
| Sequence lifelines | comment | `#6e7781` | `#8b949e` | 4.17 / 4.12 (3:1 required) |

Text needs 4.5:1 (WCAG 1.4.3), and edges, icons and lifelines need 3:1 (1.4.11). Context
participants in sequence views are drawn at 0.82 opacity, so text is also checked against the
faded card. Two shades are a step stronger than the theme uses: light titles take purple[6]
(purple[5] `#8250df` gives 3.67:1 on a faded card), and the badge uses a deeper red than lane
labels for the same reason.

Card borders stay light (1.3:1 and 1.9:1). They are decorative: each card is identified by its
label, icon and shadow. After a renderer upgrade, run `node check-contrast.mjs` and confirm the
new SVGs no longer contain the renderer greys, because `palette.mjs` matches the renderer's exact
edge colour and its class names (`ntitle`, `nsub`, `ltext`, `lanelabel`, `glyph`, `lifeline`, `bdg-neutral`).

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
