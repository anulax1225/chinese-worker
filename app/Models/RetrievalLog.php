<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RetrievalLog extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'query',
        'query_expansion',
        'retrieved_chunks',
        'retrieval_strategy',
        'retrieval_scores',
        'execution_time_ms',
        'chunks_found',
        'average_score',
        'user_found_helpful',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'query_expansion' => 'array',
            'retrieved_chunks' => 'array',
            'retrieval_scores' => 'array',
            'execution_time_ms' => 'float',
            'average_score' => 'float',
            'user_found_helpful' => 'boolean',
        ];
    }

    /**
     * Get the conversation this log belongs to.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the user this log belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope to filter by strategy.
     */
    public function scopeStrategy($query, string $strategy)
    {
        return $query->where('retrieval_strategy', $strategy);
    }

    /**
     * Scope to filter by date range.
     */
    public function scopeBetween($query, $start, $end)
    {
        return $query->whereBetween('created_at', [$start, $end]);
    }

    /**
     * Mark this retrieval as helpful or not.
     */
    public function markHelpfulness(bool $helpful): void
    {
        $this->update(['user_found_helpful' => $helpful]);
    }
}
