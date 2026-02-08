<?php

namespace App\Models;

use App\DTOs\ChatMessage;
use App\DTOs\TokenUsage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
     * Get the documents attached to this conversation.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'conversation_documents')
            ->withPivot(['preview_chunks', 'preview_tokens', 'attached_at']);
    }

    /**
     * Get the messages for the conversation (relational).
     */
    public function conversationMessages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('position');
    }

    /**
     * Check if conversation has any attached documents.
     */
    public function hasDocuments(): bool
    {
        return $this->documents()->exists();
    }

    /**
     * Get document IDs attached to this conversation.
     *
     * @return array<int>
     */
    public function getDocumentIds(): array
    {
        return $this->documents()->pluck('documents.id')->toArray();
    }

    /**
     * Add a message to the conversation.
     *
     * @param  array<string, mixed>|ChatMessage  $message
     */
    public function addMessage(array|ChatMessage $message): Message
    {
        $dto = $message instanceof ChatMessage ? $message : ChatMessage::fromArray($message);

        $position = $this->conversationMessages()->max('position') ?? -1;
        $position++;

        $messageModel = $this->conversationMessages()->create([
            'position' => $position,
            'role' => $dto->role,
            'name' => $dto->name,
            'content' => $dto->content,
            'thinking' => $dto->thinking,
            'token_count' => $dto->tokenCount,
            'tool_call_id' => $dto->toolCallId,
            'counted_at' => $dto->countedAt,
        ]);

        // Create tool calls if present
        if ($dto->toolCalls) {
            foreach ($dto->toolCalls as $index => $toolCall) {
                $messageModel->toolCalls()->create([
                    'id' => $toolCall['call_id'] ?? $toolCall['id'] ?? uniqid('call_'),
                    'function_name' => $toolCall['name'],
                    'arguments' => $toolCall['arguments'] ?? [],
                    'position' => $index,
                ]);
            }
        }

        // Create attachments for images if present
        if ($dto->images) {
            foreach ($dto->images as $imagePath) {
                $messageModel->attachments()->create([
                    'type' => 'image',
                    'mime_type' => 'image/png',
                    'storage_path' => $imagePath,
                ]);
            }
        }

        $this->last_activity_at = now();
        $this->save();

        return $messageModel;
    }

    /**
     * Get all messages in the conversation as ChatMessage DTOs.
     *
     * @return array<ChatMessage>
     */
    public function getMessages(): array
    {
        return $this->conversationMessages()
            ->with(['toolCalls', 'attachments'])
            ->get()
            ->map(fn (Message $m) => $m->toChatMessage())
            ->all();
    }

    /**
     * Get all messages as arrays (for backward compatibility).
     *
     * @return array<array<string, mixed>>
     */
    public function getMessagesAsArrays(): array
    {
        return array_map(fn (ChatMessage $m) => $m->toArray(), $this->getMessages());
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
     * Get the context usage percentage.
     */
    public function getContextUsagePercentage(): float
    {
        return $this->getTokenUsage()->getUsagePercentage() ?? 0.0;
    }

    /**
     * Recalculate estimated context usage from message token counts.
     */
    public function recalculateContextUsage(): void
    {
        $total = $this->conversationMessages()->sum('token_count');

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
     * Check if conversation is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Mark conversation as cancelled.
     */
    public function markAsCancelled(): void
    {
        $this->update([
            'status' => 'cancelled',
            'completed_at' => now(),
            'last_activity_at' => now(),
        ]);
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
