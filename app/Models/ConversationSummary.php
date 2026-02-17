<?php

namespace App\Models;

use App\Enums\SummaryStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationSummary extends Model
{
    /** @use HasFactory<\Database\Factories\ConversationSummaryFactory> */
    use HasFactory, HasUlids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'status',
        'from_position',
        'to_position',
        'content',
        'token_count',
        'backend_used',
        'model_used',
        'summarized_message_ids',
        'original_token_count',
        'metadata',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SummaryStatus::class,
            'from_position' => 'integer',
            'to_position' => 'integer',
            'token_count' => 'integer',
            'original_token_count' => 'integer',
            'summarized_message_ids' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * Get the conversation that owns this summary.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Scope to only completed summaries.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', SummaryStatus::Completed);
    }

    /**
     * Scope to only pending summaries.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SummaryStatus::Pending);
    }

    /**
     * Scope to only processing summaries.
     */
    public function scopeProcessing(Builder $query): Builder
    {
        return $query->where('status', SummaryStatus::Processing);
    }

    /**
     * Scope to only failed summaries.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', SummaryStatus::Failed);
    }

    /**
     * Mark the summary as processing.
     */
    public function markAsProcessing(): bool
    {
        return $this->update(['status' => SummaryStatus::Processing]);
    }

    /**
     * Mark the summary as completed with content.
     *
     * @param  array<string, mixed>  $data  Summary data including content, token_count, etc.
     */
    public function markAsCompleted(array $data): bool
    {
        return $this->update([...$data, 'status' => SummaryStatus::Completed]);
    }

    /**
     * Mark the summary as failed with error message.
     */
    public function markAsFailed(string $errorMessage): bool
    {
        return $this->update([
            'status' => SummaryStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Calculate the compression ratio (summary tokens / original tokens).
     */
    public function getCompressionRatio(): float
    {
        if (! $this->original_token_count || $this->original_token_count === 0) {
            return 0.0;
        }

        return $this->token_count / $this->original_token_count;
    }

    /**
     * Get the number of tokens saved by this summary.
     */
    public function getTokensSaved(): int
    {
        if (! $this->original_token_count || ! $this->token_count) {
            return 0;
        }

        return max(0, $this->original_token_count - $this->token_count);
    }
}
