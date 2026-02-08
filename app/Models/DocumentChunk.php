<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentChunk extends Model
{
    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

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
        'metadata',
        'created_at',
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
            'metadata' => 'array',
            'created_at' => 'datetime',
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
}
