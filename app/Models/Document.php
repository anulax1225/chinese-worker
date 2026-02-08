<?php

namespace App\Models;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStageType;
use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'file_id',
        'title',
        'source_type',
        'source_path',
        'mime_type',
        'file_size',
        'status',
        'error_message',
        'metadata',
        'processing_started_at',
        'processing_completed_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'source_type' => DocumentSourceType::class,
            'metadata' => 'array',
            'file_size' => 'integer',
            'processing_started_at' => 'datetime',
            'processing_completed_at' => 'datetime',
        ];
    }

    /**
     * Get the user that owns the document.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the file associated with the document.
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    /**
     * Get the processing stages for this document.
     */
    public function stages(): HasMany
    {
        return $this->hasMany(DocumentStage::class);
    }

    /**
     * Get the chunks for this document.
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class);
    }

    /**
     * Scope a query to only include ready documents.
     */
    public function scopeReady(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Ready);
    }

    /**
     * Scope a query to only include failed documents.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Failed);
    }

    /**
     * Scope a query to only include processing documents.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DocumentStatus::Extracting,
            DocumentStatus::Cleaning,
            DocumentStatus::Normalizing,
            DocumentStatus::Chunking,
        ]);
    }

    /**
     * Scope a query to only include pending documents.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DocumentStatus::Pending);
    }

    /**
     * Mark the document with a new status.
     */
    public function markAs(DocumentStatus $status): void
    {
        $updates = ['status' => $status];

        if ($status->isProcessing() && $this->processing_started_at === null) {
            $updates['processing_started_at'] = now();
        }

        if ($status->isComplete()) {
            $updates['processing_completed_at'] = now();
        }

        $this->update($updates);
    }

    /**
     * Mark the document as failed with an error message.
     */
    public function fail(string $message): void
    {
        $this->update([
            'status' => DocumentStatus::Failed,
            'error_message' => $message,
            'processing_completed_at' => now(),
        ]);
    }

    /**
     * Check if the document is ready.
     */
    public function isReady(): bool
    {
        return $this->status === DocumentStatus::Ready;
    }

    /**
     * Check if the document is processing.
     */
    public function isProcessing(): bool
    {
        return $this->status->isProcessing();
    }

    /**
     * Check if the document processing failed.
     */
    public function isFailed(): bool
    {
        return $this->status === DocumentStatus::Failed;
    }

    /**
     * Get the latest stage of a specific type.
     */
    public function getLatestStage(DocumentStageType $stage): ?DocumentStage
    {
        return $this->stages()
            ->where('stage', $stage)
            ->latest('created_at')
            ->first();
    }

    /**
     * Get the word count from metadata.
     */
    public function getWordCount(): ?int
    {
        return $this->metadata['word_count'] ?? null;
    }

    /**
     * Get the chunk count.
     */
    public function getChunkCount(): int
    {
        return $this->chunks()->count();
    }

    /**
     * Get the total tokens across all chunks.
     */
    public function getTotalTokens(): int
    {
        return $this->chunks()->sum('token_count');
    }
}
