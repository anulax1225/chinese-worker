<?php

namespace App\Models;

use App\DTOs\TokenUsage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Conversation extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'agent_id',
        'user_id',
        'status',
        'messages',
        'system_prompt_snapshot',
        'prompt_context_snapshot',
        'model_config_snapshot',
        'metadata',
        'turn_count',
        'total_tokens',
        'prompt_tokens',
        'completion_tokens',
        'context_limit',
        'estimated_context_usage',
        'started_at',
        'last_activity_at',
        'completed_at',
        'cli_session_id',
        'waiting_for',
        'pending_tool_request',
        'client_type',
        'client_tool_schemas',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'prompt_context_snapshot' => 'array',
            'model_config_snapshot' => 'array',
            'metadata' => 'array',
            'pending_tool_request' => 'array',
            'client_tool_schemas' => 'array',
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Get the agent that owns the conversation.
     */
    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    /**
     * Get the user that owns the conversation.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Add a message to the conversation.
     */
    public function addMessage(array $message): void
    {
        $messages = $this->messages ?? [];
        $messages[] = $message;

        $this->messages = $messages;
        $this->last_activity_at = now();
        $this->save();
    }

    /**
     * Get all messages in the conversation.
     */
    public function getMessages(): array
    {
        return $this->messages ?? [];
    }

    /**
     * Increment turn count.
     */
    public function incrementTurn(): void
    {
        $this->increment('turn_count');
        $this->touch('last_activity_at');
    }

    /**
     * Add tokens to the total count.
     */
    public function addTokens(int $tokens): void
    {
        $this->increment('total_tokens', $tokens);
    }

    /**
     * Add token usage with prompt/completion breakdown.
     */
    public function addTokenUsage(int $promptTokens, int $completionTokens): void
    {
        $this->increment('prompt_tokens', $promptTokens);
        $this->increment('completion_tokens', $completionTokens);
        $this->increment('total_tokens', $promptTokens + $completionTokens);
    }

    /**
     * Get the token usage for this conversation.
     */
    public function getTokenUsage(): TokenUsage
    {
        return new TokenUsage(
            promptTokens: $this->prompt_tokens ?? 0,
            completionTokens: $this->completion_tokens ?? 0,
            totalTokens: $this->total_tokens ?? 0,
            contextLimit: $this->context_limit,
        );
    }

    /**
     * Check if conversation is approaching context limit.
     */
    public function isApproachingContextLimit(float $threshold = 0.8): bool
    {
        return $this->getTokenUsage()->isApproachingLimit($threshold);
    }

    /**
     * Recalculate estimated context usage from message token counts.
     */
    public function recalculateContextUsage(): void
    {
        $total = collect($this->getMessages())
            ->sum(fn (array $message) => $message['token_count'] ?? 0);

        $this->update(['estimated_context_usage' => $total]);
    }

    /**
     * Mark conversation as started.
     */
    public function markAsStarted(): void
    {
        $this->update([
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Mark conversation as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Check if conversation is waiting for tool result.
     */
    public function isWaitingForTool(): bool
    {
        return $this->waiting_for === 'tool_result';
    }

    /**
     * Check if conversation is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Get the current request turn count.
     */
    public function getRequestTurnCount(): int
    {
        return $this->metadata['request_turn_count'] ?? 0;
    }

    /**
     * Increment the request turn count.
     */
    public function incrementRequestTurn(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['request_turn_count'] = ($metadata['request_turn_count'] ?? 0) + 1;
        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Reset the request turn count (called when user sends a new message).
     */
    public function resetRequestTurnCount(): void
    {
        $metadata = $this->metadata ?? [];
        $metadata['request_turn_count'] = 0;
        $this->metadata = $metadata;
        $this->save();
    }

    /**
     * Get the max turns limit for this conversation.
     */
    public function getMaxTurns(): int
    {
        return $this->metadata['max_turns'] ?? config('agent.max_turns', 25);
    }
}
