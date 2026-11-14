// Writes graph.json. Every element describes current behavior (0.1.1), so all deltas are "unchanged".
import { writeFileSync } from "node:fs";

const SHA = "4cd8284265d08af8cf4a573fa90c214d15b30cde";
const f = (...paths) => paths.map((path) => ({ path }));
const node = (id, label, kind, lane, extra = {}) => ({ id, label, kind, lane, delta: "unchanged", files: [], badges: [], ...extra });
const edge = (id, from, to, kind, label, extra = {}) => ({ id, from, to, kind, label, delta: "unchanged", emphasis: "normal", animated: false, files: [], ...extra });
const msg = (id, from, to, label, kind = "sync", extra = {}) => ({ id, from, to, label, kind, delta: "unchanged", animated: true, files: [], ...extra });
const part = (node, label) => ({ node, label });

const lanes = [
  { id: "actors", label: "Callers", order: 0 },
  { id: "loupe", label: "Loupe Search", subtitle: "plugin, 1.5.0+", order: 1 },
  { id: "lss", label: "Loupe Semantic Search", subtitle: "this add-on", order: 2 },
  { id: "ai", label: "WordPress ai plugin", subtitle: "1.5.0+", order: 3 },
  { id: "external", label: "External", order: 4 },
];

const nodes = [
  node("editor", "Editor", "ui", "actors", { subtitle: "saves a post" }),
  node("visitor", "Visitor / Search block", "ui", "actors"),
  node("agent", "AI agent / MCP client", "external", "actors"),
  node("operator", "Administrator / WP-CLI", "ui", "actors"),

  node("loupe-engine", "WP_Loupe_Search_Engine", "module", "loupe", { subtitle: "/?s=, loupe-search/search" }),
  node("loupe-rest", "WP_Loupe_REST", "route", "loupe", { subtitle: "POST /search" }),
  node("loupe-rules", "Loupe indexability rules", "function", "loupe", { subtitle: "is_post_indexable, public?" }),

  node("hybrid", "Hybrid_Search", "module", "lss", { subtitle: "RRF fusion via Fusion::rrf()", files: f("includes/Hybrid_Search.php", "includes/Fusion.php") }),
  node("abilities", "Abilities", "route", "lss", { subtitle: "retrieve, search", files: f("includes/Abilities.php") }),
  node("admin-ui", "Settings tab and CLI", "ui", "lss", { subtitle: "wp loupe-semantic", files: f("includes/Admin/Settings_Page.php", "includes/CLI/Command.php") }),
  node("retriever", "Retriever", "module", "lss", { subtitle: "search(), passages()", files: f("includes/Retriever.php") }),
  node("embedder", "Query_Embedder", "module", "lss", { subtitle: "cache + limits", files: f("includes/Query_Embedder.php", "includes/Rate_Limiter.php") }),
  node("store", "Vector store", "module", "lss", { subtitle: "cosine scan", files: f("includes/Ai_Repository_Vector_Store.php", "includes/Vector_Store.php") }),
  node("passages", "Passage_Builder", "module", "lss", { subtitle: "chunk text or stale excerpt", files: f("includes/Passage_Builder.php") }),
  node("indexability", "Indexability", "function", "lss", { subtitle: "wpai_embedding_sync_is_indexable", files: f("includes/Indexability.php") }),
  node("adapter", "Ai_Adapter", "module", "lss", { subtitle: "only caller of the ai plugin", badges: ["boundary"], files: f("includes/Ai_Adapter.php") }),

  node("ai-sync", "Embedding_Sync", "job", "ai", { subtitle: "queue, chunker, backfill" }),
  node("ai-listener", "Change_Listener", "module", "ai", { subtitle: "covered types" }),
  node("ai-queue", "Sync_Queue", "queue", "ai"),
  node("ai-worker", "Sync_Worker", "job", "ai", { subtitle: "via Object_Processor" }),
  node("ai-source", "Post_Source", "function", "ai", { subtitle: "get_text()" }),
  node("ai-chunker", "Text_Chunker", "function", "ai", { subtitle: "chunk()" }),
  node("ai-repo", "Embedding repository", "datastore", "ai", { subtitle: "vectors + hash" }),

  node("provider", "Embedding provider", "external", "external", { subtitle: "AI Client + Connectors" }),
];

const edges = [
  edge("visitor-engine", "visitor", "loupe-engine", "http", "/?s="),
  edge("visitor-rest", "visitor", "loupe-rest", "http", "POST /search"),
  edge("agent-abilities", "agent", "abilities", "call", "abilities"),
  edge("operator-admin", "operator", "admin-ui", "call", "settings, wp loupe-semantic"),
  edge("engine-hybrid", "loupe-engine", "hybrid", "event", "loupe_search_order_results", { emphasis: "hero" }),
  edge("rest-hybrid", "loupe-rest", "hybrid", "event", "loupe_search_advanced_hits", { emphasis: "hero" }),
  edge("abilities-engine", "abilities", "loupe-engine", "call", "keyword hits (search)"),
  edge("abilities-hybrid", "abilities", "hybrid", "call", "apply() (search)"),
  edge("abilities-retriever", "abilities", "retriever", "call", "passages() (retrieve)"),
  edge("admin-retriever", "admin-ui", "retriever", "call", "passages() (wp query)"),
  edge("hybrid-retriever", "hybrid", "retriever", "call", "search()"),
  edge("retriever-embedder", "retriever", "embedder", "call", "embed query"),
  edge("retriever-store", "retriever", "store", "call", "nearest posts"),
  edge("retriever-passages", "retriever", "passages", "call", "passage text"),
  edge("retriever-rules", "retriever", "loupe-rules", "call", "indexable / public?"),
  edge("indexability-rules", "indexability", "loupe-rules", "call", "veto"),
  edge("embedder-adapter", "embedder", "adapter", "call", "embed()"),
  edge("store-adapter", "store", "adapter", "call", "iterate vectors"),
  edge("passages-adapter", "passages", "adapter", "call", "text, hash, chunks"),
  edge("admin-adapter", "admin-ui", "adapter", "call", "models, backfill, status"),
  edge("adapter-sync", "adapter", "ai-sync", "call", "consumer, backfill, prune"),
  edge("adapter-repo", "adapter", "ai-repo", "data", "read vectors"),
  edge("sync-indexability", "ai-sync", "indexability", "event", "is_indexable filter"),
  edge("sync-repo", "ai-sync", "ai-repo", "data", "store vectors"),
  edge("sync-provider", "ai-sync", "provider", "http", "embed chunks"),
  edge("editor-listener", "editor", "ai-listener", "event", "post saved"),
  edge("adapter-listener", "adapter", "ai-listener", "call", "register_embedding_consumer()"),
  edge("listener-queue", "ai-listener", "ai-queue", "data", "enqueue"),
  edge("worker-queue", "ai-worker", "ai-queue", "data", "drain"),
  edge("worker-source", "ai-worker", "ai-source", "call", "post text"),
  edge("worker-chunker", "ai-worker", "ai-chunker", "call", "split into chunks"),
  edge("worker-provider", "ai-worker", "provider", "http", "embed chunks"),
  edge("worker-repo", "ai-worker", "ai-repo", "data", "store_for_object()"),
  edge("adapter-provider", "adapter", "provider", "http", "query vectors, model list"),
];

const flows = [
  {
    id: "hybrid-search",
    title: "Hybrid front-end search",
    delta: "unchanged",
    participants: [
      part("visitor", "Visitor"),
      part("loupe-engine", "Loupe engine"),
      part("hybrid", "Hybrid_Search"),
      part("retriever", "Retriever"),
      part("embedder", "Query_Embedder"),
      part("adapter", "Ai_Adapter"),
      part("provider", "Provider"),
      part("store", "Vector store"),
      part("loupe-rules", "Loupe rules"),
    ],
    messages: [
      msg("query", "visitor", "loupe-engine", "GET /?s=query"),
      msg("keyword", "loupe-engine", "loupe-engine", "keyword hits", "self"),
      msg("order-filter", "loupe-engine", "hybrid", "loupe_search_order_results"),
      msg("search", "hybrid", "retriever", "search(), 50 candidates"),
      msg("embed", "retriever", "embedder", "embed(query), 30 min cache", "sync", { note: "Cached vector returned for 30 min; otherwise a rate-limit slot is taken." }),
      msg("adapter-embed", "embedder", "adapter", "uncached: rate limit, embed(), 1.5 s", "sync", { note: "Uncached queries only." }),
      msg("provider-call", "adapter", "provider", "embedding request"),
      msg("provider-vector", "provider", "adapter", "vector", "return"),
      msg("vector", "adapter", "embedder", "vector", "return"),
      msg("query-vector", "embedder", "retriever", "query vector", "return"),
      msg("nearest", "retriever", "store", "nearest posts (3 x limit + 10)"),
      msg("matches", "store", "retriever", "best chunk per post >= min_similarity", "return"),
      msg("indexable", "retriever", "loupe-rules", "indexable?"),
      msg("indexable-result", "loupe-rules", "retriever", "drop posts Loupe would not index", "return"),
      msg("semantic", "retriever", "hybrid", "semantic matches", "return"),
      msg("fuse", "hybrid", "hybrid", "RRF fuse (-term: rerank only)", "self", { note: "With a -term exclusion, semantic matches only reorder keyword hits." }),
      msg("fused", "hybrid", "loupe-engine", "fused hits", "return"),
      msg("results", "loupe-engine", "visitor", "results (cached by Loupe)", "return"),
    ],
  },
  {
    id: "hybrid-fallback",
    title: "Hybrid search when the semantic side fails",
    delta: "unchanged",
    participants: [
      part("visitor", "Visitor"),
      part("loupe-engine", "Loupe engine"),
      part("hybrid", "Hybrid_Search"),
      part("retriever", "Retriever"),
    ],
    messages: [
      msg("query", "visitor", "loupe-engine", "GET /?s=query"),
      msg("order-filter", "loupe-engine", "hybrid", "loupe_search_order_results"),
      msg("search", "hybrid", "retriever", "search()"),
      msg("error", "retriever", "hybrid", "WP_Error: no model, limit, provider", "return", { note: "No model, rate limited, daily cap, or provider error/timeout." }),
      msg("unchanged", "hybrid", "loupe-engine", "keyword hits unchanged", "return"),
      msg("results", "loupe-engine", "visitor", "keyword results", "return"),
    ],
  },
  {
    id: "rag-retrieve",
    title: "RAG retrieval (loupe-semantic/retrieve)",
    delta: "unchanged",
    participants: [
      part("agent", "AI agent"),
      part("abilities", "Abilities"),
      part("retriever", "Retriever"),
      part("embedder", "Query_Embedder"),
      part("store", "Vector store"),
      part("loupe-rules", "Loupe rules"),
      part("passages", "Passage_Builder"),
    ],
    messages: [
      msg("call", "agent", "abilities", "loupe-semantic/retrieve", "sync", { note: "Users who can read (filter loupe_semantic_ability_permission)." }),
      msg("limits", "abilities", "abilities", "public indexed types, limit 1-20", "self"),
      msg("passages", "abilities", "retriever", "passages(public_only)"),
      msg("embed", "retriever", "embedder", "embed(query)"),
      msg("vector", "embedder", "retriever", "query vector", "return"),
      msg("nearest", "retriever", "store", "nearest posts"),
      msg("matches", "store", "retriever", "best chunk per post", "return"),
      msg("check", "retriever", "loupe-rules", "indexable and publicly viewable?"),
      msg("kept", "loupe-rules", "retriever", "kept posts", "return"),
      msg("build", "retriever", "passages", "build(post, chunk, hash)"),
      msg("text", "passages", "retriever", "chunk text, or excerpt with stale: true", "return"),
      msg("result", "retriever", "abilities", "passages", "return"),
      msg("reply", "abilities", "agent", "passages with URLs + model", "return"),
    ],
  },
];

const graph = {
  schemaVersion: "0.3.1",
  kind: "graph",
  id: "loupe-semantic-search-architecture",
  title: "Loupe Semantic Search architecture (current, 0.1.1)",
  summary: "Current behavior of Loupe Semantic Search 0.1.1, drawn from docs/architecture.md.",
  lenses: ["architecture", "data-flow"],
  provenance: {
    repo: { owner: "soderlind", name: "loupe-semantic-search", host: "github.com" },
    base: { sha: SHA, ref: "main" },
    head: { sha: SHA, ref: "main" },
    generator: { name: "illustrate-grill" },
  },
  lanes,
  nodes,
  edges,
  flows,
  views: [
    {
      id: "system-context",
      title: "System context (current)",
      lens: "architecture",
      scope: { kind: "selection", nodes: ["visitor", "agent", "operator", "loupe-engine", "loupe-rest", "loupe-rules", "hybrid", "abilities", "admin-ui", "indexability", "adapter", "ai-sync", "ai-repo", "provider"] },
      defaultOpen: true,
    },
    {
      id: "retrieval-components",
      title: "Retrieval components (current)",
      lens: "architecture",
      scope: { kind: "selection", nodes: ["hybrid", "abilities", "admin-ui", "retriever", "embedder", "store", "passages", "adapter", "loupe-rules"] },
    },
    {
      id: "design-overview",
      title: "Indexing and search (current)",
      lens: "architecture",
      scope: { kind: "selection", nodes: ["editor", "visitor", "loupe-engine", "hybrid", "retriever", "embedder", "store", "adapter", "ai-listener", "ai-queue", "ai-worker", "ai-source", "ai-chunker", "ai-repo", "provider"] },
    },
    { id: "hybrid-search-view", title: "Hybrid front-end search (current)", lens: "data-flow", scope: { kind: "selection", flows: ["hybrid-search"] } },
    { id: "hybrid-fallback-view", title: "Semantic side fails (current)", lens: "data-flow", scope: { kind: "selection", flows: ["hybrid-fallback"] } },
    { id: "rag-retrieve-view", title: "RAG retrieval (current)", lens: "data-flow", scope: { kind: "selection", flows: ["rag-retrieve"] } },
  ],
  layout: { direction: "right", laneOrder: ["actors", "loupe", "lss", "ai", "external"] },
};

writeFileSync(new URL("./graph.json", import.meta.url), JSON.stringify(graph, null, 2) + "\n");
console.log("wrote graph.json");
