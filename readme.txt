=== Loupe Semantic Search ===
Contributors: PerS
Tags: search, semantic search, embeddings, rag, ai
Requires at least: 7.0.3
Tested up to: 7.1
Requires PHP: 8.3
Requires Plugins: loupe-search, ai
Stable tag: 0.1.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Semantic retrieval and hybrid search for Loupe Search, using embeddings from a connected AI provider.

== Description ==

Loupe Search finds words. Loupe Semantic Search also finds meaning: a search for "cheap flights" can match a post about "budget air travel".

* **Hybrid search**: keyword and semantic rankings are merged for the front-end search, the REST search endpoint (Loupe Search Block) and the loupe-search/search ability.
* **RAG retrieval**: the loupe-semantic/retrieve ability returns the passages that best answer a question, with title and URL, for AI agents.

Embeddings are created by the provider you connect under Settings → Connectors, through the AI plugin. Embedding content and search queries costs provider calls. Query vectors are cached, and there is a per-visitor rate limit and a daily cap. When the provider is unavailable, search falls back to keyword results.

The built-in vector search scans all vectors and suits sites with up to about 20,000 chunks (a few thousand posts). Larger sites can plug in a vector database with the `loupe_semantic_vector_store` filter.

== Installation ==

1. Install and activate Loupe Search 1.5.0+, the AI plugin 1.5.0+ and this plugin.
2. Connect a provider that offers embedding models under Settings → Connectors.
3. Go to Settings → Loupe Search → Semantic search, choose an embedding model and save. Content is embedded in the background.

== Frequently Asked Questions ==

= Which providers work? =

Any provider connected under Settings → Connectors that offers embedding models, for example OpenAI.

= Is my content sent to the provider? =

Yes. The title and content of every post Loupe Search indexes, and every search query, are sent to the connected provider to create embeddings.

== Changelog ==

= 0.1.1 =
* The Semantic search tab opens faster: the list of embedding models is saved for 12 hours instead of being fetched from the provider on every page load. A "Refresh model list" link fetches it again.

= 0.1.0 =
* First release: hybrid search, RAG retrieval abilities, a Semantic search tab in Settings → Loupe Search and WP-CLI command.
