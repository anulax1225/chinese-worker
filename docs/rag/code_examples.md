# RAG Implementation: Ready-to-Use Code Examples

## 1. Database Migration

```php
// database/migrations/2024_02_15_000001_create_vector_storage.php

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Enable pgvector extension
        DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        
        // Rename old table
        if (Schema::hasTable('document_chunks')) {
            Schema::rename('document_chunks', 'document_chunks_legacy');
        }
        
        // Create new chunks table with vector support
        Schema::create('document_chunks_v2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->onDelete('cascade');
            $table->integer('chunk_index');
            
            // Content
            $table->longText('content');
            $table->integer('token_count');
            $table->integer('start_offset');
            $table->integer('end_offset');
            
            // Structure metadata
            $table->string('section_title')->nullable();
            $table->string('chunk_type')->default('standard'); // standard, semantic, sliding_window, summary
            $table->json('headers')->nullable(); // breadcrumb: ['Title', 'Section', 'Subsection']
            
            // Vector embeddings
            $table->unsignedBigInteger('embedding_dimensions')->default(1536);
            $table->json('embedding_raw')->nullable(); // Backup for small vectors
            // Use raw SQL for pgvector column: see down() method
            
            $table->string('embedding_model')->default('text-embedding-3-small');
            $table->timestamp('embedding_generated_at')->nullable();
            
            // Sparse vector for hybrid search
            $table->json('sparse_vector')->nullable(); // {term: frequency, ...}
            
            // Quality and ranking
            $table->float('quality_score')->default(1.0); // 0-1, how confident we are in this chunk
            $table->integer('access_count')->default(0); // How often retrieved
            $table->timestamp('last_accessed_at')->nullable();
            
            // Metadata
            $table->json('metadata')->nullable();
            $table->string('source_type')->default('document'); // document, web, api
            $table->string('language')->default('en');
            
            // Timestamps
            $table->timestamps();
            
            // Indexes for filtering
            $table->index('document_id');
            $table->index('chunk_type');
            $table->index('language');
            $table->index(['document_id', 'chunk_index']);
            $table->index('embedding_generated_at');
        });
        
        // Add pgvector column using raw SQL
        DB::statement('ALTER TABLE document_chunks_v2 ADD COLUMN embedding vector(1536)');
        
        // Create HNSW index for vector search (faster than IVFFlat)
        DB::statement('
            CREATE INDEX idx_chunks_embedding_hnsw 
            ON document_chunks_v2 
            USING hnsw (embedding vector_cosine_ops)
            WITH (m=16, ef_construction=64)
        ');
        
        // Create GIN index for sparse vector (keyword matching)
        DB::statement('CREATE INDEX idx_chunks_sparse_vector ON document_chunks_v2 USING GIN (sparse_vector)');
        
        // Embedding cache table
        Schema::create('embedding_cache', function (Blueprint $table) {
            $table->id();
            $table->string('content_hash', 64); // SHA256
            $table->json('embedding_raw')->nullable(); // For backup
            $table->string('embedding_model');
            $table->string('language')->default('en');
            $table->timestamps();
            $table->unique(['content_hash', 'embedding_model', 'language']);
            $table->index('content_hash');
        });
        
        // Add pgvector column
        DB::statement('ALTER TABLE embedding_cache ADD COLUMN embedding vector(1536)');
        
        // Retrieval logging
        Schema::create('retrieval_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            
            $table->text('query');
            $table->json('query_expansion')->nullable(); // Alternative queries tried
            
            $table->json('retrieved_chunks'); // Array of chunk IDs and scores
            $table->string('retrieval_strategy'); // dense, sparse, hybrid, hyde, multi_query
            
            $table->json('retrieval_scores'); // Score breakdown
            $table->float('execution_time_ms');
            
            // For evaluation
            $table->integer('chunks_found')->default(0);
            $table->float('average_score')->nullable();
            $table->boolean('user_found_helpful')->nullable();
            
            $table->timestamps();
            
            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('created_at');
            $table->index('retrieval_strategy');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retrieval_logs');
        Schema::dropIfExists('embedding_cache');
        Schema::dropIfExists('document_chunks_v2');
        
        // Restore old table if needed
        if (Schema::hasTable('document_chunks_legacy')) {
            Schema::rename('document_chunks_legacy', 'document_chunks');
        }
    }
};
```

---

## 2. DocumentChunk Model

```php
// app/Models/DocumentChunk.php

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    use HasFactory;
    
    protected $table = 'document_chunks_v2';
    protected $fillable = [
        'document_id', 'chunk_index', 'content', 'token_count',
        'start_offset', 'end_offset', 'section_title', 'chunk_type',
        'headers', 'embedding_raw', 'embedding_model', 'embedding_generated_at',
        'sparse_vector', 'quality_score', 'access_count', 'last_accessed_at',
        'metadata', 'source_type', 'language', 'embedding_dimensions'
    ];
    
    protected $casts = [
        'headers' => 'array',
        'embedding_raw' => 'array',
        'sparse_vector' => 'array',
        'metadata' => 'array',
        'embedding_generated_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'quality_score' => 'float',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
    
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
    
    /**
     * Record that this chunk was accessed
     */
    public function recordAccess(): void
    {
        $this->update([
            'access_count' => $this->access_count + 1,
            'last_accessed_at' => now(),
        ]);
    }
    
    /**
     * Get formatted citation for this chunk
     */
    public function getCitation(): string
    {
        $doc = $this->document;
        $header = $this->section_title ? " → {$this->section_title}" : '';
        return "{$doc->filename}{$header} (Chunk {$this->chunk_index})";
    }
    
    /**
     * Get unique identifier for source tracking
     */
    public function getSourceLine(): string
    {
        return "{$this->document_id}#{$this->chunk_index}";
    }
    
    /**
     * Scope: find chunks semantically similar to embedding
     * 
     * Usage: DocumentChunk::semanticSearch($embedding, topK: 10, threshold: 0.3)
     */
    public function scopeSemanticSearch($query, array $embedding, int $topK = 10, float $threshold = 0.3)
    {
        $embeddingString = '[' . implode(',', $embedding) . ']';
        
        return $query
            ->selectRaw(
                "*, (1 - (embedding <=> ?::vector)) as similarity",
                [$embeddingString]
            )
            ->whereRaw(
                "(1 - (embedding <=> ?::vector)) > ?",
                [$embeddingString, $threshold]
            )
            ->orderByRaw("similarity DESC")
            ->limit($topK);
    }
    
    /**
     * Scope: filter by language
     */
    public function scopeLanguage($query, string $language)
    {
        return $query->where('language', $language);
    }
    
    /**
     * Scope: filter by chunk type
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('chunk_type', $type);
    }
    
    /**
     * Scope: only chunks with embeddings
     */
    public function scopeWithEmbeddings($query)
    {
        return $query->whereNotNull('embedding_generated_at');
    }
    
    /**
     * Scope: chunks from specific documents
     */
    public function scopeFromDocuments($query, $documentIds)
    {
        $ids = is_array($documentIds) ? $documentIds : [$documentIds];
        return $query->whereIn('document_id', $ids);
    }
}
```

---

## 3. EmbeddingService

```php
// app/Services/Embedding/EmbeddingService.php

<?php

namespace App\Services\Embedding;

use App\Contracts\AIBackendInterface;
use App\Models\DocumentChunk;
use App\Models\EmbeddingCache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

class EmbeddingService
{
    public function __construct(
        private AIBackendInterface $backend,
        private TokenCounterService $tokenCounter,
    ) {}
    
    /**
     * Embed a single text
     * Returns array of floats (the embedding vector)
     */
    public function embed(string $text, ?string $model = null): array
    {
        $model = $model ?? config('ai.embedding.default_model');
        $cached = $this->getFromCache($text, $model);
        
        if ($cached) {
            return $cached;
        }
        
        $embedding = $this->generateEmbedding($text, $model);
        $this->storeInCache($text, $embedding, $model);
        
        return $embedding;
    }
    
    /**
     * Embed multiple texts (batch processing)
     */
    public function embedBatch(array $texts, ?string $model = null): array
    {
        $model = $model ?? config('ai.embedding.default_model');
        $embeddings = [];
        $toEmbed = [];
        $toEmbedIndices = [];
        
        // Check cache for each text
        foreach ($texts as $index => $text) {
            if ($cached = $this->getFromCache($text, $model)) {
                $embeddings[$index] = $cached;
            } else {
                $toEmbed[] = $text;
                $toEmbedIndices[] = $index;
            }
        }
        
        // Batch generate uncached embeddings
        if (!empty($toEmbed)) {
            $newEmbeddings = $this->backend->generateEmbeddings($toEmbed, $model);
            
            foreach ($newEmbeddings as $i => $embedding) {
                $originalIndex = $toEmbedIndices[$i];
                $embeddings[$originalIndex] = $embedding;
                
                // Store in cache
                $this->storeInCache($toEmbed[$i], $embedding, $model);
            }
        }
        
        // Return in original order
        ksort($embeddings);
        return array_values($embeddings);
    }
    
    /**
     * Embed all chunks of a document
     */
    public function embedDocumentChunks(int $documentId, ?string $model = null): void
    {
        $model = $model ?? config('ai.embedding.default_model');
        
        $chunks = DocumentChunk::where('document_id', $documentId)
            ->whereNull('embedding_generated_at')
            ->get();
        
        if ($chunks->isEmpty()) {
            return;
        }
        
        $texts = $chunks->pluck('content')->toArray();
        $embeddings = $this->embedBatch($texts, $model);
        
        foreach ($chunks as $index => $chunk) {
            // Save as array first
            $embeddingArray = $embeddings[$index];
            
            $chunk->update([
                'embedding_raw' => $embeddingArray, // Backup as JSON
                'embedding_model' => $model,
                'embedding_generated_at' => now(),
                'embedding_dimensions' => count($embeddingArray),
            ]);
            
            // Now update pgvector column directly
            $embeddingString = '[' . implode(',', $embeddingArray) . ']';
            $chunk->getConnection()
                ->statement(
                    'UPDATE document_chunks_v2 SET embedding = ?::vector WHERE id = ?',
                    [$embeddingString, $chunk->id]
                );
        }
    }
    
    /**
     * Generate sparse embedding (BM25 term frequencies)
     * Used for hybrid search
     */
    public function generateSparseEmbedding(string $text): array
    {
        // Simple tokenization
        $tokens = str_word_count(strtolower($text), 1);
        
        // Remove common stop words
        $stopwords = ['the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for'];
        $tokens = array_filter($tokens, fn($t) => !in_array($t, $stopwords));
        
        // Calculate term frequencies
        $termFrequencies = array_count_values($tokens);
        
        // Normalize by max frequency
        if (empty($termFrequencies)) {
            return [];
        }
        
        $maxFreq = max($termFrequencies);
        $normalized = [];
        
        foreach ($termFrequencies as $term => $freq) {
            $normalized[$term] = round($freq / $maxFreq, 3);
        }
        
        return $normalized;
    }
    
    /**
     * Update embeddings for chunks (re-embedding)
     */
    public function reembedChunks(Collection $chunks, ?string $model = null): void
    {
        // Simply call embedBatch - caching will be skipped if different model
        $texts = $chunks->pluck('content')->toArray();
        $embeddings = $this->embedBatch($texts, $model);
        
        foreach ($chunks as $index => $chunk) {
            $embeddingArray = $embeddings[$index];
            $embeddingString = '[' . implode(',', $embeddingArray) . ']';
            
            $chunk->update([
                'embedding_raw' => $embeddingArray,
                'embedding_model' => $model,
                'embedding_generated_at' => now(),
            ]);
            
            $chunk->getConnection()
                ->statement(
                    'UPDATE document_chunks_v2 SET embedding = ?::vector WHERE id = ?',
                    [$embeddingString, $chunk->id]
                );
        }
    }
    
    /**
     * Check if text exists in cache
     */
    private function getFromCache(string $text, string $model): ?array
    {
        if (!config('ai.embedding.cache_embeddings')) {
            return null;
        }
        
        $hash = $this->hashContent($text, $model);
        
        $cached = EmbeddingCache::where('content_hash', $hash)
            ->where('embedding_model', $model)
            ->first();
        
        if ($cached) {
            // Return as array - PostgreSQL returns JSON
            if ($cached->embedding_raw) {
                return $cached->embedding_raw;
            }
            
            // Or reconstruct from pgvector
            // This is a simplified approach - in reality you'd query differently
            return null;
        }
        
        return null;
    }
    
    /**
     * Store embedding in cache
     */
    private function storeInCache(string $text, array $embedding, string $model): void
    {
        if (!config('ai.embedding.cache_embeddings')) {
            return;
        }
        
        $hash = $this->hashContent($text, $model);
        $embeddingString = '[' . implode(',', $embedding) . ']';
        
        EmbeddingCache::updateOrCreate(
            [
                'content_hash' => $hash,
                'embedding_model' => $model,
            ],
            [
                'embedding_raw' => $embedding,
            ]
        );
        
        // Update pgvector column
        $cached = EmbeddingCache::where('content_hash', $hash)
            ->where('embedding_model', $model)
            ->first();
        
        if ($cached) {
            $cached->getConnection()
                ->statement(
                    'UPDATE embedding_cache SET embedding = ?::vector WHERE id = ?',
                    [$embeddingString, $cached->id]
                );
        }
    }
    
    /**
     * Generate single embedding via backend
     */
    private function generateEmbedding(string $text, string $model): array
    {
        $result = $this->backend->generateEmbeddings([$text], $model);
        return $result[0] ?? [];
    }
    
    /**
     * Hash content for cache key
     */
    private function hashContent(string $text, string $model): string
    {
        return hash('sha256', "{$text}::{$model}");
    }
}
```

---

## 4. RetrievalService - Dense Search

```php
// app/Services/Retrieval/RetrievalService.php (Part 1: Dense)

<?php

namespace App\Services\Retrieval;

use App\Models\DocumentChunk;
use App\Services\Embedding\EmbeddingService;
use Illuminate\Support\Collection;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embeddingService,
    ) {}
    
    /**
     * Dense vector search (semantic similarity)
     * Uses pgvector to find semantically similar chunks
     */
    public function denseSearch(
        string $query,
        $documents,
        array $options = []
    ): array {
        $model = $options['embedding_model'] ?? config('ai.embedding.default_model');
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        $threshold = $options['threshold'] ?? config('ai.retrieval.similarity_threshold');
        
        // Embed query
        $queryEmbedding = $this->embeddingService->embed($query, $model);
        
        // Build base query
        $query = $this->buildBaseQuery($documents);
        
        // Perform semantic search using pgvector
        $results = $query
            ->selectRaw(
                "*, (1 - (embedding <=> ?::vector)) as similarity",
                [json_encode($queryEmbedding)]
            )
            ->whereRaw(
                "(embedding IS NOT NULL AND 1 - (embedding <=> ?::vector) > ?)",
                [json_encode($queryEmbedding), $threshold]
            )
            ->orderByRaw("similarity DESC")
            ->limit($topK)
            ->get();
        
        return [
            'chunks' => $results,
            'scores' => $results->mapWithKeys(fn($chunk) => [
                $chunk->id => $chunk->similarity,
            ])->toArray(),
            'strategy' => 'dense',
        ];
    }
    
    /**
     * Sparse search (BM25-like keyword matching)
     */
    public function sparseSearch(
        string $query,
        $documents,
        array $options = []
    ): array {
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        
        // Generate sparse representation of query
        $sparseQuery = $this->embeddingService->generateSparseEmbedding($query);
        $queryTerms = array_keys($sparseQuery);
        
        if (empty($queryTerms)) {
            return ['chunks' => collect(), 'scores' => [], 'strategy' => 'sparse'];
        }
        
        // Build base query
        $query = $this->buildBaseQuery($documents);
        
        // Search using sparse vector (GIN index)
        $results = $query
            ->whereRaw("sparse_vector ?| ?", [json_encode($queryTerms)])
            ->orderByRaw(
                "ts_rank(to_tsvector('english', content), plainto_tsquery('english', ?)) DESC",
                [implode(' | ', $queryTerms)]
            )
            ->limit($topK)
            ->get();
        
        return [
            'chunks' => $results,
            'scores' => [], // Sparse search doesn't have normalized scores
            'strategy' => 'sparse',
        ];
    }
    
    /**
     * Hybrid search: dense + sparse with RRF (Reciprocal Rank Fusion)
     * This is the recommended default strategy
     */
    public function hybridSearch(
        string $query,
        $documents,
        array $options = []
    ): array {
        $denseResults = $this->denseSearch($query, $documents, $options);
        $sparseResults = $this->sparseSearch($query, $documents, $options);
        
        // RRF: score = 1/(rank + 60)
        // The constant 60 is empirically determined
        $fusedScores = [];
        
        foreach ($denseResults['chunks'] as $rank => $chunk) {
            $score = 1 / ($rank + 60);
            $fusedScores[$chunk->id] = ($fusedScores[$chunk->id] ?? 0) + $score;
        }
        
        foreach ($sparseResults['chunks'] as $rank => $chunk) {
            $score = 1 / ($rank + 60);
            $fusedScores[$chunk->id] = ($fusedScores[$chunk->id] ?? 0) + $score;
        }
        
        // Sort by fused score and get top K
        arsort($fusedScores);
        $topK = $options['top_k'] ?? config('ai.retrieval.top_k');
        $topIds = array_slice(array_keys($fusedScores), 0, $topK);
        
        // Fetch chunks in score order
        $chunks = collect();
        foreach ($topIds as $id) {
            $chunk = DocumentChunk::find($id);
            if ($chunk) {
                $chunk->fusion_score = $fusedScores[$id];
                $chunks->push($chunk);
            }
        }
        
        return [
            'chunks' => $chunks,
            'scores' => array_slice($fusedScores, 0, $topK, true),
            'strategy' => 'hybrid',
        ];
    }
    
    /**
     * Main retrieve method - routes to appropriate strategy
     */
    public function retrieve(
        string $query,
        $documents,
        array $options = []
    ): RetrievalResult {
        $strategy = $options['strategy'] ?? config('ai.retrieval.default_strategy');
        
        $startTime = microtime(true);
        
        $result = match($strategy) {
            'dense' => $this->denseSearch($query, $documents, $options),
            'sparse' => $this->sparseSearch($query, $documents, $options),
            'hybrid' => $this->hybridSearch($query, $documents, $options),
            default => throw new \InvalidArgumentException("Unknown retrieval strategy: {$strategy}"),
        };
        
        $executionTime = (microtime(true) - $startTime) * 1000;
        
        // Record access
        foreach ($result['chunks'] as $chunk) {
            $chunk->recordAccess();
        }
        
        return new RetrievalResult(
            chunks: $result['chunks'],
            strategy: $result['strategy'],
            scores: $result['scores'],
            executionTimeMs: $executionTime,
        );
    }
    
    /**
     * Build base query with document filtering
     */
    private function buildBaseQuery($documents)
    {
        $query = DocumentChunk::with('document')
            ->whereNotNull('embedding_generated_at');
        
        if (is_array($documents) || $documents instanceof Collection) {
            $ids = $documents instanceof Collection
                ? $documents->pluck('id')->toArray()
                : array_map(fn($d) => $d->id ?? $d, $documents);
            $query->whereIn('document_id', $ids);
        } else {
            // Single document
            $query->where('document_id', $documents->id ?? $documents);
        }
        
        return $query;
    }
}
```

---

## 5. RetrievalResult Class

```php
// app/Services/Retrieval/RetrievalResult.php

<?php

namespace App\Services\Retrieval;

use Illuminate\Support\Collection;

class RetrievalResult
{
    public function __construct(
        public Collection $chunks,
        public string $strategy,
        public array $scores = [],
        public float $executionTimeMs = 0,
    ) {}
    
    /**
     * Get total chunks retrieved
     */
    public function count(): int
    {
        return $this->chunks->count();
    }
    
    /**
     * Get average score
     */
    public function averageScore(): float
    {
        if (empty($this->scores)) {
            return 0;
        }
        
        return array_sum($this->scores) / count($this->scores);
    }
    
    /**
     * Get score for specific chunk
     */
    public function getScore(int $chunkId): ?float
    {
        return $this->scores[$chunkId] ?? null;
    }
    
    /**
     * Filter chunks by minimum score
     */
    public function withMinScore(float $threshold): self
    {
        $filtered = $this->chunks->filter(fn($chunk) => {
            $score = $this->getScore($chunk->id);
            return $score === null || $score >= $threshold;
        });
        
        return new self(
            chunks: $filtered,
            strategy: $this->strategy,
            scores: $this->scores,
            executionTimeMs: $this->executionTimeMs,
        );
    }
}
```

---

## 6. Basic Implementation in Controller

```php
// app/Http/Controllers/ConversationController.php (Example)

<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Services\Retrieval\RetrievalService;
use App\Services\Retrieval\RAGContextBuilder;
use App\Services\AI\AIBackendManager;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private RetrievalService $retriever,
        private RAGContextBuilder $contextBuilder,
        private AIBackendManager $aiManager,
    ) {}
    
    /**
     * Send message with RAG
     */
    public function sendMessage(Request $request, Conversation $conversation)
    {
        $userQuery = $request->input('message');
        $documents = $conversation->documents; // User has associated documents
        
        // 1. Retrieve relevant chunks
        $retrieval = $this->retriever->retrieve(
            query: $userQuery,
            documents: $documents,
            options: [
                'strategy' => 'hybrid', // Use hybrid search
                'top_k' => 10,
                'threshold' => 0.3,
            ]
        );
        
        // 2. Build RAG context
        $ragContext = $this->contextBuilder->formatForPrompt(
            chunks: $retrieval->chunks,
            query: $userQuery
        );
        
        // 3. Query LLM with augmented context
        $systemPrompt = "You are a helpful assistant.\n\n{$ragContext}";
        
        $response = $this->aiManager
            ->backend()
            ->chat(
                messages: array_merge(
                    [['role' => 'system', 'content' => $systemPrompt]],
                    $conversation->getFormattedMessages(),
                    [['role' => 'user', 'content' => $userQuery]],
                ),
                options: ['temperature' => 0.7]
            );
        
        // 4. Save message with metadata
        $conversation->messages()->create([
            'role' => 'assistant',
            'content' => $response,
            'metadata' => [
                'rag_chunks_count' => $retrieval->count(),
                'retrieval_strategy' => $retrieval->strategy,
                'execution_time_ms' => $retrieval->executionTimeMs,
                'average_relevance' => $retrieval->averageScore(),
            ],
        ]);
        
        return response()->json([
            'response' => $response,
            'sources' => $retrieval->chunks->map(fn($chunk) => [
                'citation' => $chunk->getCitation(),
                'excerpt' => substr($chunk->content, 0, 200) . '...',
                'relevance' => $retrieval->getScore($chunk->id),
            ]),
        ]);
    }
}
```

---

## 7. Configuration File

```php
// config/ai.php

<?php

return [
    'default_backend' => env('AI_BACKEND', 'openai'),
    
    // Embedding configuration
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
    
    // Retrieval configuration
    'retrieval' => [
        'default_strategy' => env('RETRIEVAL_STRATEGY', 'hybrid'),
        'strategies' => ['dense', 'sparse', 'hybrid'],
        'top_k' => 10,
        'similarity_threshold' => 0.3,
        'max_chunk_size' => 1000,
        'chunk_overlap' => 100,
        'cache_results' => true,
        'cache_ttl' => 3600,
    ],
    
    // Vector store
    'vector_store' => [
        'driver' => 'pgvector',
        'dimensions' => 1536,
        'similarity_metric' => 'cosine',
        'index_type' => 'hnsw',
    ],
];
```

---

## 8. Environment Variables

```bash
# .env

# AI Backend
AI_BACKEND=openai
OPENAI_API_KEY=sk-...

# Embedding
EMBEDDING_MODEL=text-embedding-3-small

# Retrieval
RETRIEVAL_STRATEGY=hybrid

# Database
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=chinese_worker
DB_USERNAME=postgres
DB_PASSWORD=secret
```

---

## 9. Service Provider Setup

```php
// app/Providers/RAGServiceProvider.php

<?php

namespace App\Providers;

use App\Services\Embedding\EmbeddingService;
use App\Services\Retrieval\RetrievalService;
use App\Services\Retrieval\RAGContextBuilder;
use Illuminate\Support\ServiceProvider;

class RAGServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(EmbeddingService::class, function ($app) {
            return new EmbeddingService(
                backend: $app->make('ai.backend'),
                tokenCounter: $app->make('token.counter'),
            );
        });
        
        $this->app->singleton(RetrievalService::class, function ($app) {
            return new RetrievalService(
                embeddingService: $app->make(EmbeddingService::class),
            );
        });
        
        $this->app->singleton(RAGContextBuilder::class);
    }
    
    public function boot(): void
    {
        //
    }
}
```

---

## Quick Start Checklist

```bash
# 1. Install dependencies
composer require pgvector/pgvector

# 2. Create migration and run it
php artisan make:migration create_vector_storage
php artisan migrate

# 3. Update AIBackend to support embeddings
# (Add generateEmbeddings() method)

# 4. Create EmbeddingService
# (See code above)

# 5. Test embedding generation
php artisan tinker
> $service = app(EmbeddingService::class);
> $embedding = $service->embed("Hello world");
> count($embedding); // Should be 1536 for text-embedding-3-small

# 6. Embed existing document chunks
php artisan tinker
> $service = app(EmbeddingService::class);
> $service->embedDocumentChunks(1); // Document ID 1

# 7. Test dense search
php artisan tinker
> $retriever = app(RetrievalService::class);
> $docs = \App\Models\Document::find(1);
> $result = $retriever->retrieve("your query", $docs);
> $result->chunks->count();

# 8. Integrate into conversation controller
# (See code above)
```

This gives you everything you need to get started!
