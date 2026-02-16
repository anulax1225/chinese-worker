# RAG Architecture Visualization & Quick Reference

## System Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                           DOCUMENT INGESTION                                │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                    ┌──────────────────┼──────────────────┐
                    ▼                  ▼                  ▼
            ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
            │   EXTRACT    │   │    CLEAN     │   │  NORMALIZE   │
            │   TEXT       │──►│   & DENOISE  │──►│  STRUCTURE   │
            │   (22 types) │   │              │   │              │
            └──────────────┘   └──────────────┘   └──────┬───────┘
                                                          │
                                    ┌─────────────────────┼─────────────────────┐
                                    ▼                     ▼                     ▼
                        ┌─────────────────────┐  ┌──────────────────┐  ┌─────────────┐
                        │ SLIDING WINDOW      │  │ SEMANTIC         │  │ RECURSIVE   │
                        │ (overlapping)       │  │ (similarity-based)│ │ (hierarchical)
                        └────────────┬────────┘  └────────┬─────────┘  └─────┬───────┘
                                     │                    │                   │
                                     └────────────────────┼───────────────────┘
                                                          ▼
                                        ┌─────────────────────────────┐
                                        │   EMBED CHUNKS              │
                                        │ - Dense (1536-dim vector)   │
                                        │ - Sparse (BM25 term freq)   │
                                        │ - Cache embeddings          │
                                        └────────────┬────────────────┘
                                                     │
                                    ┌────────────────┼────────────────┐
                                    ▼                ▼                ▼
                                ┌────────┐    ┌──────────┐    ┌──────────┐
                                │pgvector│    │ Embedding│    │  Sparse  │
                                │  Store │    │  Cache   │    │  Vector  │
                                │(HNSW)  │    │          │    │          │
                                └────────┘    └──────────┘    └──────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                           QUERY PROCESSING                                  │
└─────────────────────────────────────────────────────────────────────────────┘
                                       │
                                User Query
                                       │
                    ┌──────────────────┼──────────────────┐
                    ▼                  ▼                  ▼
            ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
            │   EMBED      │   │ EXPAND QUERY │   │  EXTRACT     │
            │   QUERY      │   │ (multi-query)│   │  CONCEPTS    │
            │   (Dense)    │   │ (HyDE)       │   │              │
            └────────┬──────┘   └──────┬───────┘   └────────┬─────┘
                     │                 │                    │
                     └─────────────────┼────────────────────┘
                                       │
                    ┌──────────────────┼──────────────────┐
                    ▼                  ▼                  ▼
            ┌──────────────┐   ┌──────────────┐   ┌──────────────┐
            │   DENSE      │   │   SPARSE     │   │    HYBRID    │
            │   SEARCH     │   │   SEARCH     │   │  (RRF Fusion)│
            │ (pgvector)   │   │  (BM25-like) │   │              │
            └────────┬──────┘   └──────┬───────┘   └────────┬─────┘
                     │                 │                    │
                     └─────────────────┼────────────────────┘
                                       ▼
                            ┌─────────────────────┐
                            │   RERANK RESULTS    │
                            │ (cross-encoder)     │
                            │ Optional but        │
                            │ highly recommended  │
                            └────────────┬────────┘
                                         ▼
                            ┌─────────────────────┐
                            │  TOP-K CHUNKS      │
                            │ (scored & ranked)   │
                            └────────────┬────────┘
                                         │
                    ┌────────────────────┼────────────────────┐
                    ▼                    ▼                    ▼
            ┌──────────────┐     ┌──────────────┐     ┌──────────────┐
            │ FORMAT WITH  │────►│  BUILD RAG   │────►│   INJECT     │
            │ CITATIONS    │     │  CONTEXT     │     │  SYSTEM      │
            │              │     │              │     │  PROMPT      │
            └──────────────┘     └──────────────┘     └────────┬─────┘
                                                                 │
                                                                 ▼
                                                    ┌─────────────────────┐
                                                    │   QUERY LLM         │
                                                    │   WITH CONTEXT      │
                                                    └────────────┬────────┘
                                                                 │
                                                                 ▼
                                                    ┌─────────────────────┐
                                                    │   RESPONSE WITH     │
                                                    │   CITATIONS         │
                                                    │   [Source 1,2,3]    │
                                                    └─────────────────────┘

┌─────────────────────────────────────────────────────────────────────────────┐
│                    VECTOR DATABASE DETAILS                                  │
└─────────────────────────────────────────────────────────────────────────────┘

PostgreSQL pgvector:
  └─ Tables:
     ├─ document_chunks_v2
     │  ├─ embedding (vector, 1536-dim)
     │  ├─ sparse_vector (json, BM25 terms)
     │  ├─ content (text)
     │  ├─ section_title (text)
     │  ├─ metadata (json)
     │  └─ indexes:
     │     ├─ HNSW on embedding (fast exact search)
     │     ├─ GIN on sparse_vector (keyword matching)
     │     └─ B-tree on document_id, chunk_type
     │
     ├─ embedding_cache
     │  ├─ content_hash (sha256)
     │  ├─ embedding (vector)
     │  └─ embedding_model (text)
     │
     └─ retrieval_logs
        ├─ query (text)
        ├─ query_embedding (vector)
        ├─ retrieved_chunks (json)
        ├─ retrieval_strategy (text)
        └─ execution_time_ms (float)
```

---

## Search Strategy Comparison

```
┌─────────────────┬──────────────────────┬────────────────────┬──────────────┐
│ Strategy        │ Advantages           │ Disadvantages      │ Best For     │
├─────────────────┼──────────────────────┼────────────────────┼──────────────┤
│ DENSE           │ • Semantic matching  │ • Slower           │ Semantic     │
│ (Vector Search) │ • Concept-aware      │ • No keyword       │ understanding│
│                 │ • Context sensitive  │   matching         │              │
├─────────────────┼──────────────────────┼────────────────────┼──────────────┤
│ SPARSE          │ • Fast               │ • No semantics     │ Keyword      │
│ (BM25)          │ • Exact matching     │ • Fails on synonyms│ matching     │
│                 │ • Low overhead       │ • Vocabulary gap   │              │
├─────────────────┼──────────────────────┼────────────────────┼──────────────┤
│ HYBRID          │ • Best coverage      │ • Slower (2x query)│ Production   │
│ (Dense+Sparse)  │ • Balances both      │ • Complex fusion   │ systems      │
│                 │ • RRF fusion proven  │                    │              │
├─────────────────┼──────────────────────┼────────────────────┼──────────────┤
│ HyDE            │ • Better semantics   │ • LLM cost         │ Complex Q&A  │
│ (Hypothetical)  │ • Expanded coverage  │ • Slower           │              │
│                 │ • Multi-perspective  │ • Non-deterministic│              │
├─────────────────┼──────────────────────┼────────────────────┼──────────────┤
│ MULTI-QUERY     │ • Comprehensive      │ • 3-5x query cost  │ Hard queries │
│ (Expansion)     │ • Better recall      │ • More latency     │ that need    │
│                 │ • Synonym handling   │ • Fusion overhead  │ rephrasing   │
└─────────────────┴──────────────────────┴────────────────────┴──────────────┘
```

---

## Component Interaction Flowchart

```
┌─────────────────────────────────────────────────────────────────────────────┐
│ Conversation Controller receives user message                                │
└──────────────────────────────────────────┬──────────────────────────────────┘
                                           │
                                           ▼
                    ┌──────────────────────────────────────┐
                    │ RAGPipeline::process()               │
                    │                                      │
                    │ 1. Get associated documents          │
                    │ 2. Call RetrievalService             │
                    └───────────────┬──────────────────────┘
                                    │
                   ┌────────────────┼────────────────┐
                   ▼                ▼                ▼
         ┌────────────────┐  ┌─────────────┐  ┌──────────────┐
         │EmbeddingService│  │QueryExpansion│ │RetrievalCache│
         │                │  │Service       │ │              │
         │embedBatch()    │  │expandQuery() │ │remember()    │
         └────────┬───────┘  └────────┬─────┘ └──────┬───────┘
                  │                   │               │
                  ▼                   ▼               ▼
         ┌────────────────────────────────────────────────────┐
         │ RetrievalService::retrieve()                       │
         │ - Calls appropriate strategy based on config       │
         ├────────────────────────────────────────────────────┤
         │ DENSE SEARCH:                                      │
         │ └─ semanticSearch(queryEmbedding, topK, threshold) │
         │                                                    │
         │ SPARSE SEARCH:                                     │
         │ └─ whereRaw(sparse_vector ?| queryTerms)           │
         │                                                    │
         │ HYBRID SEARCH:                                     │
         │ ├─ denseSearch()                                   │
         │ ├─ sparseSearch()                                  │
         │ └─ RRF fusion (1/(rank+60))                        │
         │                                                    │
         │ HYDE:                                              │
         │ ├─ generateHypotheticalDocuments()                 │
         │ ├─ embedBatch(hypotheticals)                       │
         │ ├─ computeCentroid()                               │
         │ └─ semanticSearch(centroid)                        │
         └───────────────┬──────────────────────────────────┘
                         │
                         ▼
         ┌────────────────────────────────────────────────────┐
         │ RerankingService::rerank() [Optional but rec'd]    │
         │                                                    │
         │ Option 1: Python cross-encoder (faster)            │
         │ └─ callPythonReranker(query, chunks)               │
         │                                                    │
         │ Option 2: In-process BM25-like scoring             │
         │ └─ rerankWithinProcessing(query, chunks)           │
         └───────────────┬──────────────────────────────────┘
                         │
                         ▼
         ┌────────────────────────────────────────────────────┐
         │ RAGContextBuilder::formatForPrompt()               │
         │                                                    │
         │ ├─ buildContext(chunks, query)                     │
         │ │  └─ Format with [Source N] citations             │
         │ │                                                  │
         │ └─ Returns system prompt + context                 │
         └───────────────┬──────────────────────────────────┘
                         │
                         ▼
         ┌────────────────────────────────────────────────────┐
         │ AIBackendManager::chat()                           │
         │                                                    │
         │ Send system prompt + context + message history     │
         │ to OpenAI/Ollama/etc.                              │
         └───────────────┬──────────────────────────────────┘
                         │
                         ▼
         ┌────────────────────────────────────────────────────┐
         │ Response with [Source N] citations                 │
         │                                                    │
         │ ├─ Save to conversation                            │
         │ ├─ Track retrieval metadata                        │
         │ │  ├─ rag_sources (chunk IDs used)                 │
         │ │  ├─ retrieval_strategy (dense/sparse/hybrid/hyde)│
         │ │  └─ execution_time_ms                            │
         │ │                                                  │
         │ └─ Return to user                                  │
         └────────────────────────────────────────────────────┘
```

---

## PostgreSQL pgvector Commands Reference

```sql
-- Enable extension
CREATE EXTENSION IF NOT EXISTS vector;

-- Create vector column (1536 dims for text-embedding-3-small)
ALTER TABLE document_chunks_v2 ADD COLUMN embedding vector(1536);

-- Create HNSW index (recommended for production)
CREATE INDEX idx_chunks_embedding_hnsw 
ON document_chunks_v2 
USING hnsw (embedding vector_cosine_ops)
WITH (m=16, ef_construction=64);

-- Or IVFFlat index (faster indexing, slightly slower search)
CREATE INDEX idx_chunks_embedding_ivf
ON document_chunks_v2 
USING ivfflat (embedding vector_cosine_ops)
WITH (lists=100);

-- Search by similarity (cosine distance)
SELECT id, content, 1 - (embedding <=> '[0.1, 0.2, ..., 0.9]'::vector) as similarity
FROM document_chunks_v2
WHERE (embedding <=> '[0.1, 0.2, ..., 0.9]'::vector) < 1 - 0.3  -- threshold
ORDER BY embedding <=> '[0.1, 0.2, ..., 0.9]'::vector
LIMIT 10;

-- Check index size
SELECT 
    indexname,
    pg_size_pretty(pg_relation_size(indexrelid)) AS index_size
FROM pg_indexes 
WHERE tablename = 'document_chunks_v2'
AND indexname LIKE 'idx_chunks_embedding%';

-- Analyze index effectiveness
ANALYZE document_chunks_v2;
EXPLAIN (ANALYZE, BUFFERS) SELECT ... ORDER BY embedding <=> ...;

-- Reindex if fragmented
REINDEX INDEX idx_chunks_embedding_hnsw;
```

---

## Configuration Reference

```php
// config/ai.php - Complete configuration

return [
    'default_backend' => env('AI_BACKEND', 'openai'),
    
    'embedding' => [
        'default_model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'batch_size' => 100,
        'cache_embeddings' => true,
        'cache_ttl' => 2592000, // 30 days
        
        'dimensions' => [
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'nomic-embed-text' => 768,
            'mxbai-embed-large' => 1024,
        ],
        
        'backend_defaults' => [
            'openai' => 'text-embedding-3-small',
            'ollama' => 'nomic-embed-text',
        ],
    ],
    
    'retrieval' => [
        // Which strategy to use by default
        'default_strategy' => env('RETRIEVAL_STRATEGY', 'hybrid'),
        
        // Available strategies
        'strategies' => ['dense', 'sparse', 'hybrid', 'hyde', 'multi_query'],
        
        // Retrieval parameters
        'top_k' => 10,
        'similarity_threshold' => 0.3, // Filter results below threshold
        
        // Chunk parameters
        'max_chunk_size' => 1000, // tokens
        'chunk_overlap' => 100, // tokens
        
        // Reranking
        'enable_reranking' => true,
        'reranker_backend' => 'python', // 'python' or 'in_process'
        'reranker_model' => 'ms-marco-MiniLM-L-12-v2',
        
        // Query expansion (HyDE, multi-query)
        'enable_hyde' => true,
        'enable_query_expansion' => true,
        'expansion_count' => 3,
        
        // Caching
        'cache_results' => true,
        'cache_ttl' => 3600, // 1 hour
        
        // Analytics
        'log_retrievals' => true,
        'track_execution_time' => true,
    ],
    
    'vector_store' => [
        'driver' => 'pgvector',
        'dimensions' => 1536,
        'similarity_metric' => 'cosine',
        'index_type' => 'hnsw', // hnsw or ivfflat
        'hnsw_m' => 16,
        'hnsw_ef_construction' => 64,
        'ivf_lists' => 100,
    ],
];
```

---

## Chunking Strategy Comparison

```
┌────────────────────┬──────────────────┬──────────────────┬──────────────────┐
│ Strategy           │ When to Use      │ Pros             │ Cons             │
├────────────────────┼──────────────────┼──────────────────┼──────────────────┤
│ SLIDING WINDOW     │ Default/General  │ • Overlapping    │ • Compute cost   │
│ (1000 tokens, 300  │ Documents        │   context        │ • Creates many   │
│  stride = 700 overlap)                │ • Consistent     │   chunks         │
│                    │                  │ • Predictable    │                  │
├────────────────────┼──────────────────┼──────────────────┼──────────────────┤
│ SEMANTIC           │ Long-form        │ • Natural breaks │ • Needs embed    │
│ (similarity-based) │ Documents        │ • Better meaning │   model upfront  │
│                    │ Books            │ • Fewer chunks   │ • Slower ingestion
│                    │ Articles         │                  │                  │
├────────────────────┼──────────────────┼──────────────────┼──────────────────┤
│ RECURSIVE          │ Code             │ • Hierarchical   │ • Complex logic  │
│ (multi-separator)  │ Structured data  │ • Respects       │ • Config-heavy   │
│                    │ JSON/XML         │   structure      │                  │
│                    │ Markdown         │ • Language-aware │                  │
├────────────────────┼──────────────────┼──────────────────┼──────────────────┤
│ PARAGRAPH          │ Legacy (existing)│ • Simple         │ • Uneven chunks  │
│                    │ Multi-source     │ • Reliable       │ • Context loss   │
│                    │ Documents        │                  │                  │
└────────────────────┴──────────────────┴──────────────────┴──────────────────┘
```

---

## Modern RAG Techniques Used

| Technique | Purpose | Complexity | ROI |
|-----------|---------|-----------|-----|
| **Hybrid Search (Dense + Sparse)** | Combine semantic + keyword matching | Medium | ⭐⭐⭐⭐⭐ |
| **Reciprocal Rank Fusion** | Better fusion than averaging scores | Low | ⭐⭐⭐⭐ |
| **Query Expansion (Multi-Query)** | Handle paraphrasing and synonyms | Medium | ⭐⭐⭐⭐ |
| **HyDE** | Improve semantic understanding | Medium | ⭐⭐⭐ |
| **Reranking (Cross-Encoder)** | Dramatically improve top-K relevance | Medium | ⭐⭐⭐⭐⭐ |
| **Semantic Chunking** | Break at meaning boundaries | High | ⭐⭐⭐⭐ |
| **Embedding Cache** | Reduce compute costs | Low | ⭐⭐⭐ |
| **Citation Tracking** | Trust & transparency | Low | ⭐⭐⭐⭐ |
| **HNSW Indexing** | Fast vector search at scale | Low | ⭐⭐⭐⭐⭐ |
| **Retrieval Analytics** | Monitor & improve RAG quality | Low | ⭐⭐⭐⭐ |

---

## Implementation Checklist

### Phase 1: Setup (Week 1-2)
- [ ] Install pgvector extension in PostgreSQL
- [ ] Run migrations for document_chunks_v2, embedding_cache, retrieval_logs
- [ ] Update DocumentChunk model with vector casts
- [ ] Add pgvector and dependencies to composer.json
- [ ] Update config/ai.php with embedding configuration
- [ ] Test basic vector operations in database

### Phase 2: Embeddings (Week 2-3)
- [ ] Implement AIBackendInterface::generateEmbeddings() in all backends
- [ ] Create EmbeddingService with caching
- [ ] Set up EmbeddingCache repository
- [ ] Create batch embedding job (EmbedChunksBatchJob)
- [ ] Integrate embedding into DocumentIngestionService
- [ ] Test embedding generation and caching

### Phase 3: Chunking (Week 2-3)
- [ ] Implement SemanticChunkingStrategy
- [ ] Implement SlidingWindowChunkingStrategy
- [ ] Implement RecursiveChunkingStrategy
- [ ] Update DocumentIngestionService to support multiple strategies
- [ ] Test chunking with different document types
- [ ] Benchmark chunking strategies

### Phase 4: Search (Week 3-4)
- [ ] Create RetrievalService
- [ ] Implement denseSearch() with pgvector
- [ ] Implement sparseSearch() with BM25 terms
- [ ] Implement hybridSearch() with RRF fusion
- [ ] Implement hydeSearch() with hypothetical documents
- [ ] Test each search strategy
- [ ] Benchmark search performance

### Phase 5: Query Enhancement (Week 4-5)
- [ ] Create QueryExpansionService
- [ ] Implement query expansion (alternative phrasings)
- [ ] Implement concept extraction
- [ ] Create multiQuerySearch()
- [ ] Create RerankerService
- [ ] Integrate Python cross-encoder OR in-process reranker
- [ ] Test query expansion and reranking

### Phase 6: RAG Pipeline (Week 5-6)
- [ ] Create RAGContextBuilder
- [ ] Implement context formatting with citations
- [ ] Create RAGPipeline orchestrator
- [ ] Integrate with ConversationController
- [ ] Implement citation extraction and tracking
- [ ] Test end-to-end RAG flow

### Phase 7: Optimization (Week 6+)
- [ ] Set up RetrievalCache with Redis
- [ ] Create HNSW vector indexes
- [ ] Implement RetrievalAnalytics
- [ ] Set up monitoring dashboards
- [ ] Benchmark end-to-end latency
- [ ] Load testing (100+ concurrent queries)
- [ ] Implement retrieval quality metrics (NDCG, MRR, MAP)

### Testing & QA
- [ ] Unit tests for each service
- [ ] Integration tests for full RAG pipeline
- [ ] Performance tests (latency, throughput)
- [ ] Quality tests (retrieval relevance)
- [ ] Edge cases (empty results, timeout handling, errors)
- [ ] User acceptance testing

---

## Key Metrics to Track

```
Retrieval Metrics:
  - MRR (Mean Reciprocal Rank)
  - NDCG@K (Normalized Discounted Cumulative Gain)
  - MAP (Mean Average Precision)
  - Recall@K
  - Precision@K

Performance Metrics:
  - Query embedding latency (should be <100ms)
  - Vector search latency (should be <50ms)
  - Reranking latency (should be <200ms)
  - Total RAG pipeline latency (should be <500ms)
  - Cache hit rate (target >70%)
  - Index size and memory usage

Quality Metrics:
  - User satisfaction with citations
  - False positive rate (irrelevant results)
  - Hallucination reduction
  - Source consistency
  - Answer accuracy (A/B test with manual evaluation)
```

---

## Deployment Checklist

- [ ] Database backups configured
- [ ] Vector indexes verified and optimized
- [ ] Embedding model available (API credentials or local Ollama)
- [ ] Reranker model downloaded (if using Python)
- [ ] Redis/caching layer deployed
- [ ] Monitoring and alerting set up
- [ ] Load testing completed
- [ ] Rollback plan documented
- [ ] User documentation updated
- [ ] Team training completed
