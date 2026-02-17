# RAG System

Chinese Worker includes a Retrieval-Augmented Generation (RAG) system that enriches AI responses with relevant context from your documents. When a user asks a question, the system automatically retrieves related document chunks and injects them into the prompt.

## Overview

```
┌──────────────┐    ┌──────────────┐    ┌──────────────┐    ┌──────────────┐
│  Embedding   │ →  │  Retrieval   │ →  │   Context    │ →  │     RAG      │
│   Service    │    │   Service    │    │   Builder    │    │   Pipeline   │
│              │    │              │    │              │    │              │
│ Generate &   │    │ Dense/Sparse │    │ Format with  │    │ Orchestrate  │
│ cache vectors│    │ /Hybrid search│   │ citations    │    │ end-to-end   │
└──────────────┘    └──────────────┘    └──────────────┘    └──────────────┘
```

### How It Works

1. **Documents are chunked** by the [document ingestion pipeline](document-ingestion.md)
2. **Embeddings are generated** for each chunk via a background job
3. **User asks a question** in a conversation with attached documents
4. **Relevant chunks are retrieved** using semantic and/or keyword search
5. **Context is formatted** with citations and injected into the AI prompt
6. **The AI responds** with knowledge from the retrieved documents

## Configuration

All RAG settings are in `config/ai.php` under the `rag` key:

```php
'rag' => [
    'enabled' => env('RAG_ENABLED', false),

    // Embedding configuration
    'embedding_model' => env('RAG_EMBEDDING_MODEL', 'qwen3-embedding:0.6b'),
    'embedding_backend' => env('RAG_EMBEDDING_BACKEND', 'ollama'),
    'embedding_dimensions' => env('RAG_EMBEDDING_DIMENSIONS', 1536),
    'embedding_batch_size' => 100,
    'cache_embeddings' => true,

    // Search configuration
    'search_type' => env('RAG_SEARCH_TYPE', 'hybrid'),
    'top_k' => 10,
    'similarity_threshold' => 0.3,
    'max_context_tokens' => 4000,

    // Logging
    'log_retrievals' => true,
],
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `RAG_ENABLED` | `false` | Master toggle for the RAG system |
| `RAG_EMBEDDING_MODEL` | `qwen3-embedding:0.6b` | Model used for generating embeddings |
| `RAG_EMBEDDING_BACKEND` | `ollama` | Which AI backend generates embeddings |
| `RAG_EMBEDDING_DIMENSIONS` | `1536` | Vector dimensionality |
| `RAG_SEARCH_TYPE` | `hybrid` | Retrieval strategy: `dense`, `sparse`, or `hybrid` |

### Enabling RAG

Add to your `.env`:

```env
RAG_ENABLED=true
RAG_EMBEDDING_BACKEND=ollama
RAG_EMBEDDING_MODEL=qwen3-embedding:0.6b
```

Ensure the embedding model is pulled in Ollama:

```bash
./vendor/bin/sail exec ollama ollama pull qwen3-embedding:0.6b
```

## Embedding Generation

The `EmbeddingService` converts text into vector representations for semantic search.

### How Embeddings Work

Each document chunk is transformed into a fixed-size vector (default 1536 dimensions) that captures its semantic meaning. Similar texts produce similar vectors, enabling relevance search beyond keyword matching.

### Automatic Generation

When a document finishes processing, the `EmbedDocumentChunksJob` runs automatically:

1. Fetches all chunks without embeddings
2. Processes them in batches (default 100 per batch)
3. Stores vectors in both JSON (`embedding_raw`) and pgvector (`embedding`) columns
4. Retries up to 3 times with exponential backoff (60s, 300s, 900s)

### Embedding Cache

When `cache_embeddings` is enabled, the service caches embeddings by content hash. If the same text is embedded again (e.g., after reprocessing), the cached vector is reused instead of making another API call.

### Manual Embedding

Via Tinker:

```bash
./vendor/bin/sail artisan tinker

>>> $service = app(\App\Services\RAG\EmbeddingService::class);
>>> $embedding = $service->embed('Hello world');
>>> count($embedding); // 1536
```

## Retrieval Strategies

The `RetrievalService` supports three search strategies, configured via `search_type`.

### Dense Search (Semantic Similarity)

Uses pgvector's cosine similarity to find semantically related chunks, even when they don't share keywords with the query.

```env
RAG_SEARCH_TYPE=dense
```

- Best for: conceptual questions, paraphrased queries
- Requires: populated pgvector `embedding` column
- Fallback: PHP-based cosine similarity on `embedding_raw` when pgvector is unavailable

### Sparse Search (Keyword Matching)

Uses PostgreSQL full-text search with English stemming for keyword-based retrieval.

```env
RAG_SEARCH_TYPE=sparse
```

- Best for: exact term queries, technical names, specific phrases
- No embedding required

### Hybrid Search (Recommended)

Combines dense and sparse results using Reciprocal Rank Fusion (RRF). This is the default and recommended strategy.

```env
RAG_SEARCH_TYPE=hybrid
```

- Best for: general-purpose retrieval
- Balances semantic understanding with keyword precision
- More robust across different query types

### Search Parameters

| Parameter | Default | Description |
|-----------|---------|-------------|
| `top_k` | `10` | Maximum chunks to retrieve per query |
| `similarity_threshold` | `0.3` | Minimum relevance score (0.0 - 1.0) |
| `max_context_tokens` | `4000` | Token budget for context injection |

## Context Building

The `RAGContextBuilder` formats retrieved chunks into a prompt-ready context string with citations.

### Output Format

```markdown
## Retrieved Context

The following information was retrieved from documents to help answer the query: "your query"

**[1] Document Title**

Chunk content here...

**[2] Document Title**
**Section:** Introduction

Another chunk content...

---
### Sources
[1] Document Title (Chunk 0)
[2] Document Title → Introduction (Chunk 3)
```

### Token Budget

The builder selects chunks within the `max_context_tokens` limit, allocating ~50 tokens overhead per chunk for formatting. Chunks are included in retrieval order until the budget is exhausted.

### Options

Pass options to customize context building:

```php
$builder->build($result, $query, [
    'include_metadata' => true,    // Show section titles
    'include_citations' => true,   // Add source references
    'max_context_tokens' => 2000,  // Override token budget
]);
```

## RAG Pipeline

The `RAGPipeline` orchestrates the complete workflow from query to context.

### Direct Execution

```php
$pipeline = app(RAGPipeline::class);
$result = $pipeline->execute('What does the document say about X?', $documents);

if ($result->hasContext()) {
    // Inject $result->context into the AI prompt
    // $result->citations contains source references for the UI
}
```

### Conversation-Based Execution

```php
$result = $pipeline->executeForConversation($conversation, 'What about Y?');
// Automatically uses documents attached to the conversation
```

### Pipeline Result

The `RAGPipelineResult` contains:

| Property | Type | Description |
|----------|------|-------------|
| `success` | `bool` | Whether retrieval completed |
| `reason` | `?string` | `'disabled'` or `'no_documents'` if not successful |
| `context` | `string` | Formatted context for prompt injection |
| `citations` | `array` | Citation metadata for UI display |
| `executionTimeMs` | `float` | Total pipeline latency |
| `chunksRetrieved` | `int` | Number of chunks used |

## Database Schema

The RAG system adds four database structures via pgvector-enabled migrations.

### Document Chunks (extended columns)

| Column | Type | Purpose |
|--------|------|---------|
| `embedding_raw` | JSON | Vector as JSON array (always populated) |
| `embedding` | vector(1536) | pgvector column for fast similarity search |
| `embedding_model` | string | Which model generated the embedding |
| `embedding_generated_at` | timestamp | When the embedding was created |
| `embedding_dimensions` | integer | Vector dimensionality |
| `sparse_vector` | JSONB | Term frequency map for keyword search |
| `quality_score` | float | Content quality metric (0-1) |
| `content_hash` | string(64) | SHA256 for deduplication |
| `access_count` | integer | How many times retrieved |
| `last_accessed_at` | timestamp | Last retrieval time |

### Embedding Cache

Deduplicates embedding generation for identical content:

| Column | Type | Purpose |
|--------|------|---------|
| `content_hash` | string | SHA256 of text + model |
| `embedding_raw` | JSON | Cached vector |
| `embedding` | vector(1536) | pgvector column |
| `embedding_model` | string | Model used |

Unique constraint on `(content_hash, embedding_model, language)`.

### Retrieval Logs

Analytics and debugging data for every retrieval query:

| Column | Type | Purpose |
|--------|------|---------|
| `query` | text | The search query |
| `retrieved_chunks` | JSON | Array of chunk IDs returned |
| `retrieval_strategy` | string | Strategy used (dense/sparse/hybrid) |
| `retrieval_scores` | JSON | chunk_id => score mapping |
| `execution_time_ms` | float | Query latency |
| `chunks_found` | integer | Total chunks returned |
| `average_score` | float | Mean relevance score |
| `user_found_helpful` | boolean | User feedback flag |

### PostgreSQL Indexes

- **HNSW index** on `document_chunks.embedding` for approximate nearest neighbor search
- **GIN index** on `document_chunks.sparse_vector` for keyword search
- **Full-text search index** on `document_chunks.content` with English stemming

## Testing

The RAG system includes a `FakeBackend` for testing without real AI API calls.

### FakeBackend

The `FakeBackend` implements `AIBackendInterface` with deterministic responses:

- **Embeddings**: Returns consistent vectors based on text index and configurable dimensions
- **Text generation**: Returns `"This is a fake response."`
- **Token counting**: Uses `ceil(strlen / 4)` approximation

### Test Setup

Override configuration in your test's `beforeEach`:

```php
beforeEach(function () {
    Config::set('ai.rag', [
        'enabled' => true,
        'embedding_model' => 'test-model',
        'embedding_backend' => 'fake',
        'embedding_batch_size' => 100,
        'embedding_dimensions' => 4,
        'cache_embeddings' => false,
    ]);

    Config::set('ai.backends.fake', [
        'driver' => 'fake',
        'model' => 'test-model',
        'embedding_dimensions' => 4,
    ]);
});
```

The fake backend is **not** registered in `config/ai.php` so it never appears in the backend list. It's registered programmatically in `AIBackendManager` and only activated via test configuration overrides.

### Factory States

```php
// Chunk needing embedding
DocumentChunk::factory()->needsEmbedding()->create();

// Chunk with pre-generated embedding (4 dimensions for fast tests)
DocumentChunk::factory()->withEmbedding()->create();

// With specific content
DocumentChunk::factory()->withContent('specific text')->create();
```

## Troubleshooting

### Embeddings Not Generating

1. Check RAG is enabled:
   ```bash
   ./vendor/bin/sail artisan tinker
   >>> config('ai.rag.enabled')
   ```

2. Verify the embedding backend is accessible:
   ```bash
   ./vendor/bin/sail exec ollama ollama list
   ```

3. Check the queue worker is running:
   ```bash
   ./vendor/bin/sail artisan horizon
   ```

4. Look for failed jobs:
   ```bash
   ./vendor/bin/sail artisan queue:failed
   ```

### Poor Retrieval Quality

- **Low similarity scores**: Lower `similarity_threshold` (e.g., 0.2)
- **Missing relevant chunks**: Increase `top_k` (e.g., 20)
- **Wrong strategy**: Try `hybrid` if using only `dense` or `sparse`
- **Chunk size**: Adjust chunking settings in `config/document.php` for better granularity

### pgvector Errors

If you see dimension mismatch errors, ensure `RAG_EMBEDDING_DIMENSIONS` matches the migration's `vector(1536)` column. The system automatically skips pgvector writes when dimensions don't match and falls back to `embedding_raw`.

### Checking Retrieval Logs

```bash
./vendor/bin/sail artisan tinker

>>> App\Models\RetrievalLog::latest()->first()->toArray()
```

## Next Steps

- [Document Ingestion](document-ingestion.md) - How documents are processed before RAG
- [AI Backends](ai-backends.md) - Configure the backend for embedding generation
- [Queues & Jobs](queues-and-jobs.md) - Background job processing for embeddings
- [Configuration](configuration.md) - Full configuration reference
