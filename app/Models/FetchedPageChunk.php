<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FetchedPageChunk extends Model
{
    /** @use HasFactory<\Database\Factories\FetchedPageChunkFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'fetched_page_id',
        'chunk_index',
        'content',
        'token_count',
        'start_offset',
        'end_offset',
        'section_title',
        'content_hash',
        'embedding_raw',
        'embedding_model',
        'embedding_dimensions',
        'embedding_generated_at',
        'sparse_vector',
        'quality_score',
        'access_count',
        'last_accessed_at',
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
            'embedding_raw' => 'array',
            'embedding_dimensions' => 'integer',
            'embedding_generated_at' => 'datetime',
            'sparse_vector' => 'array',
            'quality_score' => 'float',
            'access_count' => 'integer',
            'last_accessed_at' => 'datetime',
        ];
    }

    /**
     * Get the fetched page this chunk belongs to.
     */
    public function fetchedPage(): BelongsTo
    {
        return $this->belongsTo(FetchedPage::class);
    }

    /**
     * Scope to chunks that have embeddings.
     */
    public function scopeWithEmbeddings(Builder $query): Builder
    {
        return $query->whereNotNull('embedding_generated_at');
    }

    /**
     * Scope to chunks that need embedding.
     */
    public function scopeNeedsEmbedding(Builder $query): Builder
    {
        return $query->whereNull('embedding_generated_at');
    }

    /**
     * Scope to chunks for a given fetched page.
     */
    public function scopeForPage(Builder $query, int $fetchedPageId): Builder
    {
        return $query->where('fetched_page_id', $fetchedPageId);
    }

    /**
     * Check if this chunk has an embedding.
     */
    public function hasEmbedding(): bool
    {
        return $this->embedding_generated_at !== null;
    }

    /**
     * Record a retrieval access.
     */
    public function recordAccess(): void
    {
        $this->increment('access_count');
        $this->update(['last_accessed_at' => now()]);
    }

    /**
     * Generate the content hash.
     */
    public function generateContentHash(): string
    {
        return hash('sha256', $this->content);
    }
}
