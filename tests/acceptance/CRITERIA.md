# Acceptance criteria: Loupe Semantic Search

The contract for `tests/acceptance`. Every Playwright test title starts with one of these IDs.

## Environment

- Site: `http://plugins.local/loupe/` (Local, multisite blog 4, locale nb_NO), already running.
- Content: the Norwegian demo content (meta `_loupe_demo`).
- Provider: OpenAI through WP 7 Connectors. Model: **OpenAI text-embedding-3-small**. Real API calls.
- Browser: Chromium. No retries. App bugs fail the run and are reported, never weakened.
- The per-visitor rate limit is set to 0 for the run and restored afterwards (rate limiting is
  out of scope; a run exceeds 30 query embeddings per minute).
- WP-CLI runs as `bash $LSS_WP_CLI` (default: the wp-cli-local wrapper) from `$LSS_WP_ROOT`
  (default: `~/Sites/plugins/app/public`).
- State is changed through the WP-CLI wrapper, and each test restores what it changed. The
  `loupe_search_cache_*` transients are cleared before searches (a documented limitation: cached
  results survive setting changes).

## Actors

| Actor | How |
| --- | --- |
| Anonymous visitor | No cookies |
| Administrator (`per`) | Local one-click login (`localwp_auto_login`) |

## Semantic proof

"søvnløshet" (insomnia) has **zero** keyword hits on every surface, and semantic search finds
"Bedre søvn med faste rutiner". A hit therefore proves the semantic path ran.

The first choice, "unngå å kaste mat", was dropped: the target post contains "Det kaster du
mindre mat av", so Loupe's typo-tolerant keyword search finds it on its own. AC-03 to AC-05 are
also found by keyword search, so they check hybrid ranking, not semantics alone.

## J1 Admin settings

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-01 | Admin | No model is saved | On Settings → Loupe Search → Semantic search, the admin selects "OpenAI — text-embedding-3-small" and saves **once** | The admin stays on the Semantic search tab, and after a reload the select still shows that model | `includes/Settings.php` sanitize, `Admin/Settings_Page.php` |
| AC-02 | Admin | The model is saved and the backfill has run | The admin opens the Semantic search tab | The "Indeksstatus" table shows Embedded = Indexable for every post type | `Admin/Settings_Page.php` render_status |

## J2 Front-end search (`/?s=`)

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-03 | Anonymous | Front-end hybrid on | Searches "billig ferie" | "Rimelige sommerturer uten fly" is in the top 5 | `Hybrid_Search::filter_order_results` |
| AC-04 | Anonymous | Front-end hybrid on | Searches "vondt i kneet" | "Når kneet verker etter joggeturen" is in the top 5 | same |
| AC-05 | Anonymous | Front-end hybrid on | Searches "PC-en er treg" | "Datamaskinen er treg: hva kan jeg gjøre?" is in the top 5 | same |
| AC-06 | Anonymous | Front-end hybrid on | Searches "søvnløshet" | "Bedre søvn med faste rutiner" is in the top 5 | same |
| AC-07 | Anonymous | Front-end hybrid on | Searches "fårikål" | "Fårikål slik bestemor lagde den" is the first result | RRF keeps exact keyword hits on top |

## J3 Loupe Search Block / REST `POST /search`

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-08 | Anonymous | A page with the Loupe Search Block; REST hybrid on | Searches "søvnløshet" in the block | "Bedre søvn med faste rutiner" is among the results | `Hybrid_Search::filter_advanced_hits` |
| AC-09 | Anonymous | REST hybrid on | `POST /search` with `semantic:false` | The semantic-only post is not returned | `$context['payload']['semantic']` |
| AC-10 | Anonymous | A page with the block | Searches "søvnløshet" sorted "Tittel A–Z" | The semantic-only post is not among the results | `Hybrid_Search::is_relevance_sort` |

## J4 Abilities

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-11 | Admin | Abilities on | Runs `loupe-semantic/retrieve` with "søvnløshet" | Passages are returned, and one is from "Bedre søvn med faste rutiner" with non-empty text and a URL | `Abilities::retrieve` |
| AC-12 | Anonymous | Abilities on | Runs `loupe-semantic/retrieve` | The request is denied (401/403) | `Abilities::can_use` |

## J5 Toggles

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-13 | Anonymous | Front-end hybrid **off** | Searches "søvnløshet" at `/?s=` | The semantic-only post is not returned | `Settings::get('hybrid_frontend')` |

## J6 Exclusion

| ID | Actor | Given | When | Then | Evidence |
| --- | --- | --- | --- | --- | --- |
| AC-14 | Anonymous + Admin | "Bedre søvn med faste rutiner" is excluded in Loupe (`_loupe_search_exclude`) | Searches "søvnløshet" on the front end, in REST, and with `retrieve` | The post is returned by none of them | `Retriever::search` → `loupe_search_is_post_indexable` |

## Out of scope

Rate limit and daily cap, provider-failure fallback, and changing the model (full re-embed).
