<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'document_id',
        'chunk_index',
        'content',
        'token_count',
        'start_offset',
        'end_offset',
        'section_title',
        'chunk_type',
        'headers',
        'embedding_raw',
        'embedding_dimensions',
        'embedding_model',
        'embedding_generated_at',
        'sparse_vector',
        'quality_score',
        'access_count',
        'last_accessed_at',
        'metadata',
        'source_type',
        'language',
        'content_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'token_count' => 'integer',
            'start_offset' => 'integer',
            'end_offset' => 'integer',
            'embedding_dimensions' => 'integer',
            'headers' => 'array',
            'embedding_raw' => 'array',
            'sparse_vector' => 'array',
            'metadata' => 'array',
            'quality_score' => 'float',
            'access_count' => 'integer',
            'embedding_generated_at' => 'datetime',
            'last_accessed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the document that this chunk belongs to.
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * Scope a query to filter by document.
     */
    public function scopeForDocument(Builder $query, Document $document): Builder
    {
        return $query->where('document_id', $document->id);
    }

    /**
     * Scope a query to get chunks within a token budget.
     */
    public function scopeWithinTokenBudget(Builder $query, int $maxTokens): Builder
    {
        return $query->where('token_count', '<=', $maxTokens);
    }

    /**
     * Scope a query to order by chunk index.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('chunk_index');
    }

    /**
     * Scope a query to get chunks by index range.
     */
    public function scopeInRange(Builder $query, int $start, int $end): Builder
    {
        return $query->whereBetween('chunk_index', [$start, $end]);
    }

    /**
     * Scope to search within chunk content.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where('content', 'LIKE', '%'.$term.'%');
    }

    /**
     * Scope: only chunks with embeddings.
     */
    public function scopeWithEmbeddings(Builder $query): Builder
    {
        return $query->whereNotNull('embedding_generated_at');
    }

    /**
     * Scope: only chunks without embeddings.
     */
    public function scopeNeedsEmbedding(Builder $query): Builder
    {
        return $query->whereNull('embedding_generated_at');
    }

    /**
     * Scope: filter by language.
     */
    public function scopeLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    /**
     * Scope: filter by chunk type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('chunk_type', $type);
    }

    /**
     * Scope: chunks from specific documents.
     */
    public function scopeFromDocuments(Builder $query, array $documentIds): Builder
    {
        return $query->whereIn('document_id', $documentIds);
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
     * Record that this chunk was accessed.
     */
    public function recordAccess(): void
    {
        $this->update([
            'access_count' => ($this->access_count ?? 0) + 1,
            'last_accessed_at' => now(),
        ]);
    }

    /**
     * Get a preview of the content (first N characters).
     */
    public function getPreview(int $length = 200): string
    {
        if (mb_strlen($this->content) <= $length) {
            return $this->content;
        }

        return mb_substr($this->content, 0, $length).'...';
    }

    /**
     * Check if this chunk has overlap metadata.
     */
    public function hasOverlap(): bool
    {
        return isset($this->metadata['overlap_tokens'])
            && $this->metadata['overlap_tokens'] > 0;
    }

    /**
     * Get the overlap token count.
     */
    public function getOverlapTokens(): int
    {
        return $this->metadata['overlap_tokens'] ?? 0;
    }

    /**
     * Check if this chunk has an embedding.
     */
    public function hasEmbedding(): bool
    {
        return $this->embedding_generated_at !== null;
    }

    /**
     * Get formatted citation for this chunk.
     */
    public function getCitation(): string
    {
        $doc = $this->document;
        $docName = $doc?->filename ?? 'Unknown Document';
        $header = $this->section_title ? " → {$this->section_title}" : '';

        return "{$docName}{$header} (Chunk {$this->chunk_index})";
    }

    /**
     * Get unique identifier for source tracking.
     */
    public function getSourceLine(): string
    {
        return "{$this->document_id}#{$this->chunk_index}";
    }

    /**
     * Generate content hash for deduplication.
     */
    public function generateContentHash(): string
    {
        return hash('sha256', $this->content);
    }
}
