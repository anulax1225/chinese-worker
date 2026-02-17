<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessageEmbedding extends Model
{
    /** @use HasFactory<\Database\Factories\MessageEmbeddingFactory> */
    use HasFactory;

    protected $fillable = [
        'message_id',
        'conversation_id',
        'embedding_raw',
        'embedding_model',
        'embedding_dimensions',
        'embedding_generated_at',
        'sparse_vector',
        'content_hash',
        'token_count',
        'quality_score',
        'access_count',
        'last_accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'embedding_raw' => 'array',
            'embedding_dimensions' => 'integer',
            'sparse_vector' => 'array',
            'token_count' => 'integer',
            'quality_score' => 'float',
            'access_count' => 'integer',
            'embedding_generated_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Scope: only embeddings that are ready (have embedding data).
     */
    public function scopeWithEmbeddings(Builder $query): Builder
    {
        return $query->whereNotNull('embedding_generated_at');
    }

    /**
     * Scope: embeddings that need generation.
     */
    public function scopeNeedsEmbedding(Builder $query): Builder
    {
        return $query->whereNull('embedding_generated_at');
    }

    /**
     * Scope: for a specific conversation.
     */
    public function scopeForConversation(Builder $query, int $conversationId): Builder
    {
        return $query->where('conversation_id', $conversationId);
    }

    /**
     * Scope: semantic search using pgvector.
     *
     * @param  array<float>  $embedding  The query embedding
     * @param  int  $topK  Number of results to return
     * @param  float  $threshold  Minimum similarity threshold
     */
    public function scopeSemanticSearch(
        Builder $query,
        array $embedding,
        int $topK = 10,
        float $threshold = 0.3
    ): Builder {
        $embeddingString = '['.implode(',', $embedding).']';

        return $query
            ->selectRaw(
                '*, (1 - (embedding <=> ?::vector)) as similarity',
                [$embeddingString]
            )
            ->whereRaw(
                '(1 - (embedding <=> ?::vector)) > ?',
                [$embeddingString, $threshold]
            )
            ->orderByRaw('similarity DESC')
            ->limit($topK);
    }

    /**
     * Scope: hybrid search combining semantic and sparse vector search.
     *
     * @param  array<float>  $embedding  The query embedding
     * @param  array<string, float>  $sparseVector  The query sparse vector
     * @param  float  $alpha  Weight for semantic search (0-1)
     */
    public function scopeHybridSearch(
        Builder $query,
        array $embedding,
        array $sparseVector,
        int $topK = 10,
        float $alpha = 0.7,
        float $threshold = 0.3
    ): Builder {
        $embeddingString = '['.implode(',', $embedding).']';
        $sparseJson = json_encode($sparseVector);

        $semanticWeight = $alpha;
        $sparseWeight = 1 - $alpha;

        return $query
            ->selectRaw(
                '*, (? * (1 - (embedding <=> ?::vector)) + ? * COALESCE(
                    (SELECT SUM(
                        COALESCE((sparse_vector->>key)::float, 0) *
                        COALESCE(((?::jsonb)->>key)::float, 0)
                    )
                    FROM jsonb_object_keys(?::jsonb) AS key
                    ), 0)
                ) as hybrid_score',
                [$semanticWeight, $embeddingString, $sparseWeight, $sparseJson, $sparseJson]
            )
            ->whereRaw(
                '(1 - (embedding <=> ?::vector)) > ?',
                [$embeddingString, $threshold]
            )
            ->orderByRaw('hybrid_score DESC')
            ->limit($topK);
    }

    /**
     * Check if this embedding is ready.
     */
    public function hasEmbedding(): bool
    {
        return $this->embedding_generated_at !== null;
    }

    /**
     * Increment access count and update last accessed timestamp.
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Generate content hash for deduplication.
     */
    public static function hashContent(string $content): string
    {
        return hash('sha256', trim($content));
    }
}
