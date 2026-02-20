<?php

namespace App\Models;

use App\Enums\EmbeddingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Embedding extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'text',
        'text_hash',
        'embedding_raw',
        'model',
        'status',
        'error',
        'dimensions',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'embedding_raw' => 'array',
            'status' => EmbeddingStatus::class,
            'dimensions' => 'integer',
        ];
    }

    /**
     * Get the user that owns the embedding.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope a query to only include pending embeddings.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', EmbeddingStatus::Pending);
    }

    /**
     * Scope a query to only include processing embeddings.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', EmbeddingStatus::Processing);
    }

    /**
     * Scope a query to only include completed embeddings.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', EmbeddingStatus::Completed);
    }

    /**
     * Scope a query to only include failed embeddings.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', EmbeddingStatus::Failed);
    }

    /**
     * Check if the embedding is pending.
     */
    public function isPending(): bool
    {
        return $this->status === EmbeddingStatus::Pending;
    }

    /**
     * Check if the embedding is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === EmbeddingStatus::Processing;
    }

    /**
     * Check if the embedding is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === EmbeddingStatus::Completed;
    }

    /**
     * Check if the embedding processing failed.
     */
    public function isFailed(): bool
    {
        return $this->status === EmbeddingStatus::Failed;
    }

    /**
     * Mark the embedding as processing.
     */
    public function markAsProcessing(): void
    {
        $this->update(['status' => EmbeddingStatus::Processing]);
    }

    /**
     * Mark the embedding as completed with the embedding data.
     *
     * @param  array<float>  $embedding
     */
    public function markAsCompleted(array $embedding, int $dimensions): void
    {
        $this->update([
            'status' => EmbeddingStatus::Completed,
            'embedding_raw' => $embedding,
            'dimensions' => $dimensions,
            'error' => null,
        ]);
    }

    /**
     * Mark the embedding as failed with an error message.
     */
    public function markAsFailed(string $error): void
    {
        $this->update([
            'status' => EmbeddingStatus::Failed,
            'error' => $error,
        ]);
    }

    /**
     * Generate the text hash for cache lookup.
     */
    public static function hashText(string $text, string $model): string
    {
        return hash('sha256', "{$text}::{$model}");
    }
}
