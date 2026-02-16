# Enhanced RAG Implementation Plan - Chinese Worker
## With pgvector + Modern Retrieval Techniques

---

## Overview

This plan enhances the basic RAG approach with:
- **Dense + Sparse Retrieval** (hybrid search)
- **Multi-strategy chunking** (semantic chunks + sliding window)
- **Query expansion** (HyDE - Hypothetical Document Embeddings)
- **Reranking** (cross-encoder models)
- **Adaptive retrieval** (query routing)
- **Citation accuracy** (chunk provenance tracking)
- **Caching & optimization** (embedding cache, vector index optimization)

---

## Phase 1: Foundation & Infrastructure (Weeks 1-2)

### 1.1 PostgreSQL + pgvector Setup

**Migration: Create vector storage schema**
```php
// database/migrations/2024_02_15_000001_create_vector_tables.php

Schema::create('document_chunks_v2', function (Blueprint $table) {
    $table->id();
    $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
    $table->integer('chunk_index');
    
    // Content
    $table->longText('content');
    $table->integer('token_count');
    $table->integer('start_offset');
    $table->integer('end_offset');
    
    // Structure
    $table->string('section_title')->nullable();
    $table->string('chunk_type')->default('standard'); // standard, semantic, summary
    $table->json('headers')->nullable(); // breadcrumb hierarchy
    
    // Embedding
    $table->vector('embedding', 1536)->nullable(); // OpenAI dim, adjust for Ollama
    $table->string('embedding_model')->default('text-embedding-3-small');
    $table->timestamp('embedding_generated_at')->nullable();
    
    // Sparse representation (BM25 for hybrid search)
    $table->json('sparse_vector')->nullable(); // Stores term frequencies
    
    // Metadata & provenance
    $table->json('metadata')->nullable();
    $table->string('source_type')->default('document'); // document, web, api
    $table->string('language')->default('en');
    $table->float('quality_score')->default(1.0); // 0-1 relevance confidence
    
    // Tracking
    $table->timestamps();
    $table->timestamp('last_accessed_at')->nullable();
    $table->integer('access_count')->default(0);
    
    // Indexes
    $table->index('document_id');
    $table->index('chunk_type');
    $table->index('language');
});

// Create vector index for semantic search
DB::statement('CREATE INDEX idx_chunks_embedding ON document_chunks_v2 USING ivfflat (embedding vector_cosine_ops) WITH (lists=100)');

// Alternative: HNSW index for better performance
// DB::statement('CREATE INDEX idx_chunks_embedding ON document_chunks_v2 USING hnsw (embedding vector_cosine_ops) WITH (m=16, ef_construction=64)');

// Create index for sparse search (jsonb)
DB::statement('CREATE INDEX idx_chunks_sparse_vector ON document_chunks_v2 USING GIN (sparse_vector)');

Schema::create('embedding_cache', function (Blueprint $table) {
    $table->id();
    $table->text('content_hash'); // SHA256 of content
    $table->vector('embedding', 1536);
    $table->string('embedding_model');
    $table->string('language')->default('en');
    $table->timestamps();
    $table->unique(['content_hash', 'embedding_model']);
});

Schema::create('retrieval_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('conversation_id')->nullable()->constrained('conversations')->onDelete('cascade');
    $table->text('query');
    $table->vector('query_embedding', 1536);
    $table->json('retrieved_chunks'); // Store which chunks were retrieved
    $table->json('retrieval_scores');
    $table->string('retrieval_strategy'); // dense, sparse, hybrid, hyde, etc
    $table->float('execution_time_ms');
    $table->timestamps();
    $table->index('conversation_id');
    $table->index('created_at');
});
```

**Update DocumentChunk model:**
```php
// app/Models/DocumentChunk.php

class DocumentChunk extends Model
{
    use HasFactory;
    
    protected $table = 'document_chunks_v2';
    
    protected $casts = [
        'embedding' => AsVector::class, // Laravel pgvector package
        'sparse_vector' => 'array',
        'metadata' => 'array',
        'headers' => 'array',
    ];
    
    public function document()
    {
        return $this->belongsTo(Document::class);
    }
    
    // Accessor for citation
    public function getCitation(): string
    {
        $doc = $this->document;
        $header = $this->section_title ? " - {$this->section_title}" : '';
        return "{$doc->filename}{$header} (Chunk {$this->chunk_index})";
    }
    
    // For chunk provenance tracking
    public function getSourceLine(): string
    {
        return "{$this->document->id}#{$this->chunk_index}";
    }
    
    // Scope for semantic search
    public function scopeSemanticSearch($query, array $embedding, int $limit = 10, float $threshold = 0.3)
    {
        return $query
            ->select('*')
            ->selectRaw('1 - (embedding <=> ?::vector) as similarity', [$embedding])
            ->whereRaw('1 - (embedding <=> ?::vector) > ?', [$embedding, $threshold])
            ->orderBy('similarity', 'desc')
            ->limit($limit);
    }
}
```

**Add pgvector to composer.json:**
```json
{
    "require": {
        "pgvector/pgvector": "^0.2.0",
        "laravel-ready/pgvector": "^1.0"
    }
}
```

**Update config/ai.php:**
```php
return [
    'embedding' => [
        'default_model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => [
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'nomic-embed-text' => 768,
            'mxbai-embed-large' => 1024,
        ],
        'backends' => [
            'openai' => 'text-embedding-3-small',
            'ollama' => 'nomic-embed-text',
        ],
        'cache_embeddings' => true,
        'cache_ttl' => 2592000, // 30 days
    ],
    'retrieval' => [
        'default_strategy' => 'hybrid', // dense, sparse, hybrid, hyde
        'top_k' => 10,
        'similarity_threshold' => 0.3,
        'max_chunk_size' => 1000, // tokens
        'enable_reranking' => true,
        'reranker_model' => 'cross-encoder/ms-marco-MiniLM-L-12-v2',
        'enable_hyde' => true, // Hypothetical Document Embeddings
    ],
];
```

---

## Phase 2: Enhanced Chunking Strategies (Weeks 2-3)

### 2.1 Multi-Strategy Chunking Service

**Create chunking strategies:**
```php
// app/Services/Document/ChunkingStrategy.php

interface ChunkingStrategyInterface
{
    public function chunk(string $text, array $metadata = []): array;
    public function getName(): string;
}

// app/Services/Document/Strategies/ParagraphChunkingStrategy.php
class ParagraphChunkingStrategy implements ChunkingStrategyInterface
{
    public function chunk(string $text, array $metadata = []): array
    {
        // Existing implementation - split by paragraphs
    }
}

// app/Services/Document/Strategies/SemanticChunkingStrategy.php
/**
 * Splits text at semantic boundaries using sentence embeddings
 * Groups sentences with high similarity together
 */
class SemanticChunkingStrategy implements ChunkingStrategyInterface
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private TokenCounterService $tokenCounter,
        private int $maxTokens = 1000
    ) {}
    
    public function chunk(string $text, array $metadata = []): array
    {
        // 1. Split text into sentences
        $sentences = $this->splitSentences($text);
        
        // 2. Embed each sentence
        $sentenceEmbeddings = $this->embeddingService->embedBatch($sentences);
        
        // 3. Group sentences by semantic similarity
        $clusters = $this->clusterBySimilarity($sentences, $sentenceEmbeddings);
        
        // 4. Ensure chunks don't exceed token limit
        $chunks = $this->enforceTokenLimit($clusters);
        
        return array_map(fn($chunk) => new Chunk(
            content: $chunk,
            type: 'semantic',
            tokenCount: $this->tokenCounter->count($chunk)
        ), $chunks);
    }
    
    private function clusterBySimilarity(array $sentences, array $embeddings): array
    {
        $clusters = [];
        $currentCluster = [$sentences[0]];
        $lastEmbedding = $embeddings[0];
        
        for ($i = 1; $i < count($sentences); $i++) {
            $similarity = $this->cosineSimilarity($lastEmbedding, $embeddings[$i]);
            
            if ($similarity > 0.7) {
                $currentCluster[] = $sentences[$i];
            } else {
                $clusters[] = implode(' ', $currentCluster);
                $currentCluster = [$sentences[$i]];
            }
            $lastEmbedding = $embeddings[$i];
        }
        
        if (!empty($currentCluster)) {
            $clusters[] = implode(' ', $currentCluster);
        }
        
        return $clusters;
    }
}

// app/Services/Document/Strategies/SlidingWindowChunkingStrategy.php
/**
 * Creates overlapping chunks with configurable window and stride
 * Better for preserving context across chunk boundaries
 */
class SlidingWindowChunkingStrategy implements ChunkingStrategyInterface
{
    public function __construct(
        private int $windowSizeTokens = 1000,
        private int $strideTokens = 300, // Overlap = window - stride
        private TokenCounterService $tokenCounter
    ) {}
    
    public function chunk(string $text, array $metadata = []): array
    {
        $sentences = $this->splitSentences($text);
        $chunks = [];
        $currentChunk = [];
        $tokenCount = 0;
        
        foreach ($sentences as $sentence) {
            $sentenceTokens = $this->tokenCounter->count($sentence);
            
            if ($tokenCount + $sentenceTokens > $this->windowSizeTokens && !empty($currentChunk)) {
                // Save chunk and slide
                $chunks[] = implode(' ', $currentChunk);
                
                // Keep last N tokens for overlap
                $kept = [];
                $keptTokens = 0;
                for ($i = count($currentChunk) - 1; $i >= 0; $i--) {
                    $t = $this->tokenCounter->count($currentChunk[$i]);
                    if ($keptTokens + $t > $this->windowSizeTokens - $this->strideTokens) break;
                    array_unshift($kept, $currentChunk[$i]);
                    $keptTokens += $t;
                }
                
                $currentChunk = $kept;
                $tokenCount = $keptTokens;
            }
            
            $currentChunk[] = $sentence;
            $tokenCount += $sentenceTokens;
        }
        
        if (!empty($currentChunk)) {
            $chunks[] = implode(' ', $currentChunk);
        }
        
        return array_map(fn($chunk) => new Chunk(
            content: $chunk,
            type: 'sliding_window',
            tokenCount: $this->tokenCounter->count($chunk)
        ), $chunks);
    }
}

// app/Services/Document/Strategies/RecursiveChunkingStrategy.php
/**
 * Hierarchical chunking - tries different separators recursively
 * Best for code, structured data, documents with hierarchies
 */
class RecursiveChunkingStrategy implements ChunkingStrategyInterface
{
    private array $separators = [
        "\n\n", // Paragraphs
        "\n",   // Lines
        ". ",   // Sentences
        " ",    // Words
        "",     // Characters
    ];
    
    public function chunk(string $text, array $metadata = []): array
    {
        return $this->recursiveSplit($text, 0);
    }
    
    private function recursiveSplit(string $text, int $separatorIndex): array
    {
        $separator = $this->separators[$separatorIndex] ?? '';
        $chunks = [];
        
        if ($separatorIndex === count($this->separators) - 1) {
            // Last resort - just split
            $chunks = str_split($text, 1000);
        } else {
            if ($separator !== '') {
                $splits = explode($separator, $text);
            } else {
                $splits = [$text];
            }
            
            $goodChunks = [];
            $currentChunk = '';
            
            foreach ($splits as $split) {
                if (strlen($currentChunk . $split) <= 1000) {
                    $currentChunk .= $split . $separator;
                } else {
                    if ($currentChunk) $goodChunks[] = $currentChunk;
                    $currentChunk = $split . $separator;
                }
            }
            
            if ($currentChunk) $goodChunks[] = $currentChunk;
            
            // Recursively split large chunks
            foreach ($goodChunks as $chunk) {
                if (strlen($chunk) > 1000) {
                    $chunks = array_merge($chunks, $this->recursiveSplit($chunk, $separatorIndex + 1));
                } else {
                    $chunks[] = $chunk;
                }
            }
        }
        
        return array_filter($chunks);
    }
}
```

**Update DocumentIngestionService to use multiple strategies:**
```php
// app/Services/Document/DocumentIngestionService.php

class DocumentIngestionService
{
    private array $chunkerStrategies = [
        'sliding_window' => SlidingWindowChunkingStrategy::class,
        'semantic' => SemanticChunkingStrategy::class,
        'recursive' => RecursiveChunkingStrategy::class,
    ];
    
    public function ingestDocument(Document $document, array $options = []): void
    {
        $content = $this->extractAndClean($document);
        
        // Generate chunks with multiple strategies
        $strategies = $options['chunking_strategies'] ?? ['sliding_window'];
        
        foreach ($strategies as $strategyName) {
            $strategy = $this->resolve($this->chunkerStrategies[$strategyName]);
            $chunks = $strategy->chunk($content);
            
            // Store chunks with strategy metadata
            foreach ($chunks as $index => $chunk) {
                DocumentChunk::create([
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $chunk->content,
                    'token_count' => $chunk->tokenCount,
                    'chunk_type' => $chunk->type,
                    'metadata' => [
                        'strategy' => $strategyName,
                        ...($options['metadata'] ?? []),
                    ],
                ]);
            }
        }
    }
}
```

---

## Phase 3: Embedding Generation & Caching (Weeks 2-3)

### 3.1 EmbeddingService with Caching

```php
// app/Services/Embedding/EmbeddingService.php

class EmbeddingService
{
    public function __construct(
        private AIBackendManager $backendManager,
        private EmbeddingCacheRepository $cache,
        private TokenCounterService $tokenCounter,
    ) {}
    
    /**
     * Embed single text with caching
     */
    public function embed(string $text, string $model = null): array
    {
        $model = $model ?? config('ai.embedding.default_model');
        $hash = $this->hashContent($text, $model);
        
        // Check cache first
        if ($cached = $this->cache->get($hash)) {
            return $cached;
        }
        
        // Generate embedding
        $embedding = $this->backendManager
            ->backend('openai')
            ->generateEmbeddings([$text], $model)[0];
        
        // Store in cache
        $this->cache->store($hash, $embedding, $model);
        
        return $embedding;
    }
    
    /**
     * Embed multiple texts with batch processing
     */
    public function embedBatch(array $texts, string $model = null): array
    {
        $model = $model ?? config('ai.embedding.default_model');
        $embeddings = [];
        $toEmbed = [];
        $indices = [];
        
        // Check cache for each text
        foreach ($texts as $index => $text) {
            $hash = $this->hashContent($text, $model);
            if ($cached = $this->cache->get($hash)) {
                $embeddings[$index] = $cached;
            } else {
                $toEmbed[] = $text;
                $indices[] = $index;
            }
        }
        
        // Batch embed uncached texts
        if (!empty($toEmbed)) {
            $newEmbeddings = $this->backendManager
                ->backend('openai')
                ->generateEmbeddings($toEmbed, $model);
            
            foreach ($newEmbeddings as $i => $embedding) {
                $originalIndex = $indices[$i];
                $embeddings[$originalIndex] = $embedding;
                
                // Cache each
                $hash = $this->hashContent($toEmbed[$i], $model);
                $this->cache->store($hash, $embedding, $model);
            }
        }
        
        return $embeddings;
    }
    
    /**
     * Embed document chunks asynchronously
     */
    public function embedChunksAsync(Collection $chunks, string $model = null): void
    {
        $model = $model ?? config('ai.embedding.default_model');
        
        // Group into batches of 100
        $chunks->chunk(100)->each(function($batch) use ($model) {
            dispatch(new EmbedChunksBatchJob($batch, $model));
        });
    }
    
    /**
     * Embed chunks synchronously (for ingestion)
     */
    public function embedChunks(Collection $chunks, string $model = null): void
    {
        $model = $model ?? config('ai.embedding.default_model');
        
        $texts = $chunks->pluck('content')->toArray();
        $embeddings = $this->embedBatch($texts, $model);
        
        foreach ($chunks as $index => $chunk) {
            $chunk->update([
                'embedding' => $embeddings[$index],
                'embedding_model' => $model,
                'embedding_generated_at' => now(),
            ]);
        }
    }
    
    /**
     * Generate sparse embeddings (BM25 term frequencies)
     * for hybrid search
     */
    public function generateSparseEmbedding(string $text): array
    {
        $tokens = $this->tokenizeAndClean($text);
        $termFrequencies = array_count_values($tokens);
        
        // Normalize by document length
        $maxFreq = max($termFrequencies);
        
        return array_map(
            fn($freq) => $freq / $maxFreq,
            $termFrequencies
        );
    }
    
    private function hashContent(string $text, string $model): string
    {
        return hash('sha256', "{$text}::{$model}");
    }
}
```

**Update AIBackendInterface to support embeddings:**
```php
// app/Contracts/AIBackendInterface.php

interface AIBackendInterface
{
    // ... existing methods ...
    
    /**
     * Generate embeddings for text
     * @param array $texts Texts to embed
     * @param string|null $model Specific embedding model (overrides default)
     * @return array Array of embedding vectors
     */
    public function generateEmbeddings(array $texts, ?string $model = null): array;
    
    /**
     * Get dimensions of embedding vectors for a model
     */
    public function getEmbeddingDimensions(?string $model = null): int;
}
```

**Implement in OpenAI backend:**
```php
// app/Services/AI/Backends/OpenAIBackend.php

class OpenAIBackend implements AIBackendInterface
{
    public function generateEmbeddings(array $texts, ?string $model = null): array
    {
        $model = $model ?? config('ai.embedding.default_model');
        
        $response = $this->client->embeddings()->create([
            'model' => $model,
            'input' => $texts,
            'encoding_format' => 'float',
        ]);
        
        return collect($response->data)
            ->sortBy->index
            ->pluck('embedding')
            ->values()
            ->toArray();
    }
    
    public function getEmbeddingDimensions(?string $model = null): int
    {
        $model = $model ?? config('ai.embedding.default_model');
        return config("ai.embedding.dimensions.{$model}", 1536);
    }
}
```

---

## Phase 4: Hybrid Search with Semantic + Sparse (Weeks 3-4)

### 4.1 Hybrid Retrieval Service

```php
// app/Services/Retrieval/RetrievalService.php

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService,
        private DocumentChunkRepository $chunkRepo,
        private RetrievalLogRepository $logRepo,
    ) {}
    
    /**
     * Main retrieval endpoint - routes to appropriate strategy
     */
    public function retrieve(
        string $query,
        Document|Collection $documents,
        array $options = []
    ): RetrievalResult {
        $strategy = $options['strategy'] ?? config('ai.retrieval.default_strategy');
        
        $startTime = microtime(true);
        
        $result = match($strategy) {
            'dense' => $this->denseSearch($query, $documents, $options),
            'sparse' => $this->sparseSearch($query, $documents, $options),
            'hybrid' => $this->hybridSearch($query, $documents, $options),
            'hyde' => $this->hydeSearch($query, $documents, $options),
            default => throw new InvalidArgumentException("Unknown strategy: {$strategy}"),
        };
        
        $result->executionTimeMs = (microtime(true) - $startTime) * 1000;
        
        // Log retrieval for analytics
        $this->logRetrieval($query, $result, $strategy);
        
        return $result;
    }
    
    /**
     * Dense retrieval: pure semantic search
     */
    private function denseSearch(
        string $query,
        Document|Collection $documents,
        array $options = []
    ): RetrievalResult {
        // Embed query
        $queryEmbedding = $this->embeddingService->embed($query);
        
        // Search
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        $threshold = $options['threshold'] ?? config('ai.retrieval.similarity_threshold');
        
        $chunks = $this->buildQuery($documents)
            ->semanticSearch($queryEmbedding, $topK, $threshold)
            ->get();
        
        return new RetrievalResult(
            chunks: $chunks,
            strategy: 'dense',
            scores: $chunks->mapWithKeys(fn($chunk) => [
                $chunk->id => $chunk->similarity,
            ])->toArray(),
        );
    }
    
    /**
     * Sparse retrieval: BM25 keyword search
     */
    private function sparseSearch(
        string $query,
        Document|Collection $documents,
        array $options = []
    ): RetrievalResult {
        $sparseQuery = $this->embeddingService->generateSparseEmbedding($query);
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        
        // Convert to BM25 query format
        $queryTerms = array_keys($sparseQuery);
        
        $chunks = $this->buildQuery($documents)
            ->whereRaw(
                "sparse_vector ?| ?",
                [json_encode($queryTerms)]
            )
            ->orderByRaw(
                "ts_rank(to_tsvector('english', content), plainto_tsquery('english', ?)) DESC",
                [$query]
            )
            ->limit($topK)
            ->get();
        
        return new RetrievalResult(
            chunks: $chunks,
            strategy: 'sparse',
        );
    }
    
    /**
     * Hybrid retrieval: combine dense + sparse with RRF
     * Uses Reciprocal Rank Fusion to blend results
     */
    private function hybridSearch(
        string $query,
        Document|Collection $documents,
        array $options = []
    ): RetrievalResult {
        $denseResults = $this->denseSearch($query, $documents, $options);
        $sparseResults = $this->sparseSearch($query, $documents, $options);
        
        // Reciprocal Rank Fusion: score = 1/(rank+60)
        $fusedScores = [];
        
        foreach ($denseResults->chunks as $rank => $chunk) {
            $fusedScores[$chunk->id] = ($fusedScores[$chunk->id] ?? 0) + 1 / ($rank + 60);
        }
        
        foreach ($sparseResults->chunks as $rank => $chunk) {
            $fusedScores[$chunk->id] = ($fusedScores[$chunk->id] ?? 0) + 1 / ($rank + 60);
        }
        
        // Re-rank and limit
        arsort($fusedScores);
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        $topIds = array_slice(array_keys($fusedScores), 0, $topK);
        
        $chunks = $this->buildQuery($documents)
            ->whereIn('id', $topIds)
            ->orderByRaw(
                "FIELD(id, " . implode(',', $topIds) . ")"
            )
            ->get();
        
        return new RetrievalResult(
            chunks: $chunks,
            strategy: 'hybrid',
            scores: array_slice($fusedScores, 0, $topK, true),
        );
    }
    
    /**
     * HyDE: Generate hypothetical documents to improve retrieval
     * Generates 3-5 hypothetical relevant documents, embeds them,
     * and uses their centroid to search
     */
    private function hydeSearch(
        string $query,
        Document|Collection $documents,
        array $options = []
    ): RetrievalResult {
        // Generate hypothetical answers
        $hypotheticals = $this->generateHypotheticalDocuments($query);
        
        // Embed hypotheticals
        $hypotheticalEmbeddings = $this->embeddingService->embedBatch($hypotheticals);
        
        // Compute centroid
        $centroid = array_map(
            fn($i) => array_sum(array_column($hypotheticalEmbeddings, $i)) / count($hypotheticalEmbeddings),
            range(0, count($hypotheticalEmbeddings[0]) - 1)
        );
        
        // Search with centroid
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        $threshold = $options['threshold'] ?? config('ai.retrieval.similarity_threshold');
        
        $chunks = $this->buildQuery($documents)
            ->semanticSearch($centroid, $topK, $threshold)
            ->get();
        
        return new RetrievalResult(
            chunks: $chunks,
            strategy: 'hyde',
        );
    }
    
    /**
     * Generate hypothetical documents using LLM
     */
    private function generateHypotheticalDocuments(string $query): array
    {
        $prompt = <<<PROMPT
Given the following question, generate 3-4 hypothetical document excerpts that would directly answer this question. These should be specific, detailed passages that directly address the question.

Question: {$query}

Generate hypothetical documents (one per line, return ONLY the excerpts):
PROMPT;
        
        $response = app('ai.backend')
            ->chat([
                ['role' => 'user', 'content' => $prompt],
            ], [
                'temperature' => 0.7,
                'max_tokens' => 500,
            ]);
        
        return array_filter(array_map(
            'trim',
            explode("\n", $response)
        ));
    }
    
    private function buildQuery(Document|Collection $documents)
    {
        $query = DocumentChunk::query();
        
        if ($documents instanceof Document) {
            $query->where('document_id', $documents->id);
        } else {
            $query->whereIn('document_id', $documents->pluck('id'));
        }
        
        return $query;
    }
}
```

---

## Phase 5: Query Expansion & Reranking (Weeks 4-5)

### 5.1 Query Expansion Service

```php
// app/Services/Retrieval/QueryExpansionService.php

/**
 * Improve retrieval by expanding queries with related terms
 */
class QueryExpansionService
{
    public function __construct(
        private AIBackendManager $backendManager,
    ) {}
    
    /**
     * Expand query into related questions
     */
    public function expandQuery(string $query, int $count = 3): array
    {
        $prompt = <<<PROMPT
Given the user question, generate {$count} alternative phrasings or related questions that might find additional relevant information.

Original question: "{$query}"

Return ONLY the alternative questions, one per line. Be concise:
PROMPT;
        
        $response = $this->backendManager
            ->backend()
            ->chat([['role' => 'user', 'content' => $prompt]])
            ->content;
        
        return array_filter(
            array_map('trim', explode("\n", $response))
        );
    }
    
    /**
     * Extract key concepts from query
     */
    public function extractConcepts(string $query): array
    {
        $prompt = <<<PROMPT
Extract 3-5 key concepts, entities, or terms from this query:
"{$query}"

Return as JSON array of strings.
PROMPT;
        
        $response = $this->backendManager
            ->backend()
            ->chat([['role' => 'user', 'content' => $prompt]])
            ->content;
        
        return json_decode($response, true) ?? [];
    }
    
    /**
     * Multi-query search: expand query and search for each variant
     */
    public function multiQuerySearch(
        string $query,
        Document|Collection $documents,
        RetrievalService $retriever
    ): RetrievalResult {
        $queries = [$query, ...$this->expandQuery($query)];
        $allChunks = collect();
        $chunkFrequencies = [];
        
        foreach ($queries as $q) {
            $result = $retriever->retrieve($q, $documents, [
                'strategy' => 'hybrid',
                'top_k' => 5,
            ]);
            
            foreach ($result->chunks as $chunk) {
                $chunkFrequencies[$chunk->id] = ($chunkFrequencies[$chunk->id] ?? 0) + 1;
                $allChunks->push($chunk);
            }
        }
        
        // Re-rank by frequency (chunks appearing in multiple query variants ranked higher)
        $unique = $allChunks->unique('id')->sortBy(fn($chunk) => -$chunkFrequencies[$chunk->id]);
        
        return new RetrievalResult(
            chunks: $unique,
            strategy: 'multi_query',
        );
    }
}
```

### 5.2 Reranking Service

```php
// app/Services/Retrieval/RerankingService.php

/**
 * Rerank retrieved chunks using a cross-encoder model
 * Dramatically improves relevance of top results
 */
class RerankingService
{
    private const MODELS = [
        'fast' => 'ms-marco-MiniLM-L-6-v2',      // 6-layer, fast
        'balanced' => 'ms-marco-MiniLM-L-12-v2', // 12-layer, balanced
        'accurate' => 'cross-encoder/ms-marco-TinyBERT-L-6', // More accurate
    ];
    
    public function __construct(
        private string $pythonScriptPath = storage_path('scripts/rerank.py'),
    ) {}
    
    /**
     * Rerank chunks by relevance to query
     */
    public function rerank(
        string $query,
        Collection $chunks,
        string $modelSize = 'balanced',
        int $topK = null
    ): Collection {
        $topK = $topK ?? config('ai.retrieval.top_k');
        
        // Prepare data for Python script
        $data = [
            'query' => $query,
            'documents' => $chunks->map(fn($c) => [
                'id' => $c->id,
                'text' => substr($c->content, 0, 1000), // First 1000 chars
            ])->values()->toArray(),
            'model' => self::MODELS[$modelSize],
        ];
        
        // Call Python reranker (faster than PHP implementation)
        $scores = $this->callPythonReranker($data);
        
        // Attach scores and sort
        return $chunks->each(function($chunk) use ($scores) {
            $chunk->rerank_score = $scores[$chunk->id] ?? 0;
        })->sortByDesc('rerank_score')->take($topK);
    }
    
    /**
     * Local in-memory reranking using PHP
     * Slower but no Python dependency
     */
    public function rerankWithinProcessing(
        string $query,
        Collection $chunks,
        int $topK = null
    ): Collection {
        $topK = $topK ?? config('ai.retrieval.top_k');
        
        $queryTokens = str_word_count($query, 1);
        $querySet = array_flip($queryTokens);
        
        // Simple BM25-like scoring
        $scores = [];
        foreach ($chunks as $chunk) {
            $chunkTokens = str_word_count($chunk->content, 1);
            
            // Count term matches
            $matches = count(array_intersect($chunkTokens, $queryTokens));
            
            // TF-IDF like scoring
            $score = $matches / (count($queryTokens) * 0.5);
            
            // Boost for exact phrase matches
            if (stripos($chunk->content, $query) !== false) {
                $score *= 2;
            }
            
            $scores[$chunk->id] = $score;
        }
        
        // Sort and limit
        arsort($scores);
        $topIds = array_slice(array_keys($scores), 0, $topK);
        
        return $chunks->whereIn('id', $topIds)->sortBy(fn($c) => array_search($c->id, $topIds));
    }
}
```

---

## Phase 6: Context Building & Citation (Weeks 5-6)

### 6.1 RAG Context Builder

```php
// app/Services/Retrieval/RAGContextBuilder.php

class RAGContextBuilder
{
    public function __construct(
        private RerankingService $reranker,
    ) {}
    
    /**
     * Build context string from chunks with citations
     */
    public function buildContext(
        Collection $chunks,
        string $query,
        array $options = []
    ): string {
        // Optionally rerank
        if ($options['rerank'] ?? true) {
            $chunks = $this->reranker->rerank($query, $chunks);
        }
        
        $context = "# Retrieved Context\n\n";
        
        foreach ($chunks as $index => $chunk) {
            $citation = $this->formatCitation($chunk);
            $context .= <<<SECTION
**[Source {$index + 1}]** {$citation}
{$chunk->content}

---

SECTION;
        }
        
        return $context;
    }
    
    /**
     * Format chunk for use in system prompt
     */
    public function formatForPrompt(
        Collection $chunks,
        string $query
    ): string {
        $instructions = <<<INSTRUCTIONS
You have been provided with the following relevant information to help answer the user's question:

INSTRUCTIONS;
        
        $context = $this->buildContext($chunks, $query);
        
        $guidance = <<<GUIDANCE

When answering:
1. Use the provided information to support your response
2. Cite your sources using [Source N] notation
3. If information is not in the retrieved context, say so explicitly
4. Synthesize information across multiple sources when relevant
GUIDANCE;
        
        return $instructions . "\n\n" . $context . "\n\n" . $guidance;
    }
    
    /**
     * Extract citations from response
     */
    public function extractCitations(string $response): array
    {
        $citations = [];
        preg_match_all('/\[Source (\d+)\]/i', $response, $matches);
        
        if (!empty($matches[1])) {
            $citations = array_unique(array_map('intval', $matches[1]));
        }
        
        return $citations;
    }
    
    private function formatCitation(DocumentChunk $chunk): string
    {
        $doc = $chunk->document;
        $section = $chunk->section_title ? " → {$chunk->section_title}" : '';
        return "{$doc->filename}{$section} (Chunk {$chunk->chunk_index})";
    }
}
```

### 6.2 RAG Pipeline Integration

```php
// app/Services/Retrieval/RAGPipeline.php

class RAGPipeline
{
    public function __construct(
        private RetrievalService $retriever,
        private RAGContextBuilder $contextBuilder,
        private AIBackendManager $aiManager,
    ) {}
    
    /**
     * Full RAG pipeline: retrieve + format + query + cite
     */
    public function process(
        Conversation $conversation,
        string $userQuery,
        array $options = []
    ): string {
        // 1. Retrieve relevant chunks
        $documents = $conversation->documents; // Associated documents
        
        $retrieval = $this->retriever->retrieve(
            $userQuery,
            $documents,
            $options['retrieval'] ?? []
        );
        
        // 2. Build context with citations
        $context = $this->contextBuilder->formatForPrompt(
            $retrieval->chunks,
            $userQuery
        );
        
        // 3. Query LLM with augmented context
        $systemPrompt = $this->buildSystemPrompt($context);
        
        $response = $this->aiManager
            ->backend()
            ->chat(
                messages: array_merge(
                    [['role' => 'system', 'content' => $systemPrompt]],
                    $conversation->getFormattedMessages(),
                    [['role' => 'user', 'content' => $userQuery]],
                ),
                options: $options['llm'] ?? []
            );
        
        // 4. Track sources in conversation
        $citations = $this->contextBuilder->extractCitations($response);
        $sourceChunks = $retrieval->chunks
            ->whereIn('id', array_map(fn($c) => $c->id, $retrieval->chunks->toArray()))
            ->take(count($citations))
            ->toArray();
        
        // Store with metadata
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response,
            'metadata' => [
                'rag_sources' => array_map(fn($c) => $c->getSourceLine(), $sourceChunks),
                'retrieval_strategy' => $retrieval->strategy,
                'execution_time_ms' => $retrieval->executionTimeMs,
            ],
        ]);
        
        return $response;
    }
    
    private function buildSystemPrompt(string $context): string
    {
        return <<<PROMPT
You are a helpful assistant. {$context}

Remember to cite all information using [Source N] references.
PROMPT;
    }
}
```

---

## Phase 7: Optimization & Monitoring (Weeks 5-6)

### 7.1 Vector Index Optimization

```php
// database/migrations/2024_02_15_000002_optimize_vector_indexes.php

Schema::table('document_chunks_v2', function (Blueprint $table) {
    // HNSW index (better for vector search than IVFFlat)
    // Requires: CREATE EXTENSION IF NOT EXISTS hnsw;
    
    DB::statement('
        CREATE INDEX IF NOT EXISTS idx_chunks_embedding_hnsw 
        ON document_chunks_v2 
        USING hnsw (embedding vector_cosine_ops)
        WITH (m=16, ef_construction=64)
    ');
    
    // Additional indexes for filtering
    $table->index(['document_id', 'embedding_generated_at']);
    $table->index('chunk_type');
    $table->index('language');
});
```

### 7.2 Caching Layer

```php
// app/Services/Retrieval/RetrievalCache.php

class RetrievalCache
{
    public function __construct(
        private Cache $cache,
        private int $ttlSeconds = 3600, // 1 hour
    ) {}
    
    /**
     * Cache retrieval results
     */
    public function remember(
        string $query,
        Document|Collection $documents,
        callable $callback
    ): RetrievalResult {
        $key = $this->makeCacheKey($query, $documents);
        
        return $this->cache->remember($key, $this->ttlSeconds, $callback);
    }
    
    private function makeCacheKey(string $query, Document|Collection $documents): string
    {
        $docIds = $documents instanceof Document
            ? [$documents->id]
            : $documents->pluck('id')->toArray();
        
        return 'retrieval:' . hash('sha256', $query . '::' . implode(',', $docIds));
    }
}
```

### 7.3 Analytics & Monitoring

```php
// app/Services/Retrieval/RetrievalAnalytics.php

class RetrievalAnalytics
{
    /**
     * Analyze retrieval effectiveness
     */
    public function analyzeQueries(
        \Illuminate\Support\Carbon $from,
        \Illuminate\Support\Carbon $to
    ): array {
        $logs = RetrievalLog::whereBetween('created_at', [$from, $to])->get();
        
        return [
            'total_queries' => $logs->count(),
            'avg_execution_time_ms' => $logs->avg('execution_time_ms'),
            'retrieval_strategies' => $logs->groupBy('retrieval_strategy')->map->count(),
            'top_queries' => $logs
                ->groupBy('query')
                ->map(fn($g) => $g->count())
                ->sortDesc()
                ->take(10),
            'avg_chunks_retrieved' => $logs
                ->mapToGroups(fn($log) => ['count' => count($log->retrieved_chunks)])
                ->average('count'),
        ];
    }
    
    /**
     * Find queries with poor retrieval (low relevance)
     */
    public function findPoorRetrievals(float $scoreThreshold = 0.3): Collection
    {
        return RetrievalLog::query()
            ->get()
            ->filter(function($log) use ($scoreThreshold) {
                $avgScore = collect($log->retrieval_scores)->avg();
                return $avgScore < $scoreThreshold;
            });
    }
}
```

---

## Complete Implementation Timeline

| Week | Phase | Tasks |
|------|-------|-------|
| **1-2** | Infrastructure | pgvector setup, migrations, enhanced DocumentChunk model |
| **2-3** | Chunking | Implement semantic + sliding window strategies |
| **2-3** | Embeddings | EmbeddingService, caching, batch processing |
| **3-4** | Dense Search | Implement pgvector semantic search |
| **3-4** | Sparse Search | BM25 implementation, sparse vectors |
| **4-5** | Hybrid Search | RRF fusion, query expansion, HyDE |
| **4-5** | Reranking | Cross-encoder integration, in-process fallback |
| **5-6** | Context & Citation | RAGContextBuilder, full RAG pipeline |
| **5-6** | Optimization | Vector indexes, caching, monitoring |
| **6+** | Polish | Testing, documentation, performance tuning |

---

## Key Configuration

**config/ai.php additions:**
```php
'embedding' => [
    'default_model' => 'text-embedding-3-small',
    'batch_size' => 100,
    'cache_embeddings' => true,
],

'retrieval' => [
    'strategies' => ['dense', 'sparse', 'hybrid', 'hyde'],
    'default_strategy' => 'hybrid',
    'top_k' => 10,
    'similarity_threshold' => 0.3,
    'enable_reranking' => true,
    'enable_query_expansion' => true,
    'enable_hyde' => true,
    'cache_results' => true,
    'cache_ttl' => 3600,
],

'vector_store' => [
    'driver' => 'pgvector', // Only option for this plan
    'dimensions' => 1536, // text-embedding-3-small
    'index_type' => 'hnsw', // or 'ivfflat'
    'similarity_metric' => 'cosine',
],
```

---

## Modern Techniques Implemented

✅ **Hybrid Search**: Dense + Sparse with RRF fusion
✅ **Query Expansion**: Multi-query retrieval for coverage
✅ **HyDE**: Hypothetical document embeddings
✅ **Semantic Chunking**: Similarity-based chunking
✅ **Sliding Window**: Overlapping chunks for context
✅ **Reranking**: Cross-encoder post-processing
✅ **Citation Tracking**: Source attribution
✅ **Caching**: Embedding and result caching
✅ **Analytics**: Retrieval monitoring and optimization
✅ **pgvector**: Native PostgreSQL vector storage

---

## Next Steps

1. Create comprehensive test suite
2. Benchmark retrieval quality (NDCG, MRR, MAP metrics)
3. Set up monitoring dashboards
4. Implement A/B testing for retrieval strategies
5. Collect user feedback on answer quality
6. Fine-tune thresholds based on actual usage
