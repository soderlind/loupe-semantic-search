# Loupe Semantic Search

Adds semantic retrieval and hybrid search to [Loupe Search](https://github.com/soderlind/loupe-search).

Loupe finds words. This add-on also finds meaning: a search for "cheap flights" can match a post
about "budget air travel". It does that in two ways:

- **Hybrid search.** Loupe's keyword hits and the closest posts by meaning are merged with
  reciprocal rank fusion. This applies to the front-end search, the REST search endpoint (and so
  the Loupe Search Block) and the `loupe-search/search` ability.
- **RAG retrieval.** The `loupe-semantic/retrieve` ability returns the passages that best answer a
  question, with title and URL, so AI agents (for example through the MCP Adapter) can answer
  from your content and cite it.

## Requirements

- WordPress 7.0.3 or later, PHP 8.3 or later
- [Loupe Search](https://github.com/soderlind/loupe-search) 1.5.0 or later
- The [AI plugin](https://wordpress.org/plugins/ai/) 1.5.0 or later
- A provider connected under **Settings → Connectors** that offers embedding models (for example OpenAI)

Embeddings are created by your provider. Embedding all content, and each new search query,
costs provider calls. Query vectors are cached, and the add-on has a per-visitor rate limit and a
daily cap.

## Setup

1. Install and activate Loupe Search, the AI plugin and this plugin.
2. Connect a provider under **Settings → Connectors**.
3. Go to **Settings → Loupe Search → Semantic search**, choose an embedding model and save.
4. Embedding of all posts Loupe indexes starts in the background. Follow progress on the same
   page or with `wp loupe-semantic status`.

Until content is embedded, searches return keyword results as before.

## How it works

The AI plugin does the heavy lifting: it chunks each post (title and content), embeds the chunks
with the connected provider, stores the vectors and keeps them current when posts change. This
plugin registers an embedding consumer for the post types Loupe indexes, and applies Loupe's
rules (post status, exclusions, the `loupe_search_is_indexable` filter) to what is embedded.

At search time the query is embedded (cached for 30 minutes), compared with stored vectors by
cosine similarity, and fused with Loupe's keyword ranking:

```
score = w_keyword / (60 + keyword rank) + w_semantic / (60 + semantic rank)
```

If anything goes wrong (no model, provider slow or down, limit reached) the keyword results are
returned unchanged.

Details:

- Quotes, `-term` and `OR` are removed before the query is embedded. A query with an exclusion
  (`-term`) only reorders keyword hits, so excluded words never return through meaning.
- Hybrid is skipped when results are sorted by a field or by distance.
- REST filters (taxonomies, fields, dates, geo) also apply to posts found by meaning.
- Facet counts are computed from keyword hits only.
- A REST request can opt out with `"semantic": false`.

### Scale

Vectors live in the AI plugin's database table, which has no vector index, so every search scans
all vectors of the model. That is fine up to roughly 20,000 chunks (a few thousand posts). For
larger sites, plug in a vector database with the `loupe_semantic_vector_store` filter.

## Abilities

Both require a logged-in user (`read`). Use `loupe_semantic_ability_permission` to change that.

### `loupe-semantic/retrieve`

Input: `query` (required), `limit` (1 to 20, default 5), `post_type` (string or list).

```json
{
  "passages": [
    {
      "post_id": 12, "title": "…", "url": "…", "post_type": "post", "post_date": "…",
      "chunk_index": 2, "text": "…", "score": 0.71, "stale": false
    }
  ],
  "model": { "provider": "openai", "model": "text-embedding-3-small", "dimensions": null }
}
```

`stale` is true when the post changed after it was embedded; `text` is then an excerpt.

### `loupe-semantic/search`

Same input and output as `loupe-search/search` (`hits`, `total_hits`, `page`, `total_pages`),
hybrid ranked, public post types only.

## WP-CLI

```
wp loupe-semantic status [--format=<table|json|yaml>]
wp loupe-semantic query <text> [--limit=<n>] [--post_type=<types>] [--format=<format>]
wp loupe-semantic backfill [--reset]
```

The AI plugin's `wp ai embeddings sync` commands also work with the consumer
`loupe-semantic-search`, for example `wp ai embeddings sync run` to process the queue now.

## Filters

| Filter | Default | Purpose |
| --- | --- | --- |
| `loupe_semantic_vector_store` | built-in scan | Replace the vector search backend (`Vector_Store` instance). |
| `loupe_semantic_candidates` | 50 | Semantic matches fused with keyword hits. |
| `loupe_semantic_query_timeout` | 1.5 | Seconds to wait for a query embedding. |
| `loupe_semantic_query_cache_ttl` | 1800 | Seconds a query vector is cached. |
| `loupe_semantic_rate_limit` | setting | Query embeddings per minute per IP. |
| `loupe_semantic_daily_cap` | setting | Query embeddings per day for the site. |
| `loupe_semantic_client_ip` | `REMOTE_ADDR` | Client IP for the rate limit (e.g. behind a proxy). |
| `loupe_semantic_ability_permission` | `current_user_can( 'read' )` | Who may run the abilities. |
| `loupe_semantic_stale_excerpt_length` | 1000 | Length of a stale passage excerpt. |

## Known limitations

- Front-end results are cached by Loupe. If the semantic side falls back (for example the daily
  cap is reached), keyword-only results may stay cached for Loupe's cache lifetime.
- Changing the model re-embeds all content.

## Development

The code structure, request flows and invariants are described in [docs/architecture.md](docs/architecture.md).

```
composer install
composer test
```

## License

GPL-2.0-or-later
