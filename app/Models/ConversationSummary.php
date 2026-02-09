<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'from_position',
        'to_position',
        'content',
        'token_count',
        'backend_used',
        'model_used',
        'summarized_message_ids',
        'original_token_count',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * Get the messages that were summarized into this summary.
     */
    public function summarizedMessages(): HasMany
    {
        return $this->hasMany(Message::class, 'summary_id');
    }

    /**
     * Calculate the compression ratio (summary tokens / original tokens).
     */
    public function getCompressionRatio(): float
    {
        if ($this->original_token_count === 0) {
            return 0.0;
        }

        return $this->token_count / $this->original_token_count;
    }

    /**
     * Get the number of tokens saved by this summary.
     */
    public function getTokensSaved(): int
    {
        return max(0, $this->original_token_count - $this->token_count);
    }
}
