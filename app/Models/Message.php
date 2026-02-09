<?php

namespace App\Models;

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    /** @use HasFactory<\Database\Factories\MessageFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'conversation_id',
        'position',
        'role',
        'name',
        'content',
        'thinking',
        'token_count',
        'tool_call_id',
        'counted_at',
        'pinned',
        'is_synthetic',
        'summarized',
        'summary_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'token_count' => 'integer',
            'counted_at' => 'datetime',
            'pinned' => 'boolean',
            'is_synthetic' => 'boolean',
            'summarized' => 'boolean',
        ];
    }

    /**
     * Get the conversation that owns the message.
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Get the tool calls for the message.
     */
    public function toolCalls(): HasMany
    {
        return $this->hasMany(MessageToolCall::class)->orderBy('position');
    }

    /**
     * Get the attachments for the message.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MessageAttachment::class);
    }

    /**
     * Convert the message to a ChatMessage DTO.
     */
    public function toChatMessage(): ChatMessage
    {
        $toolCalls = null;
        if ($this->toolCalls->isNotEmpty()) {
            $toolCalls = $this->toolCalls->map(fn (MessageToolCall $tc) => $tc->toArray())->all();
        }

        $images = null;
        $imageAttachments = $this->attachments->where('type', 'image');
        if ($imageAttachments->isNotEmpty()) {
            $images = $imageAttachments->pluck('storage_path')->all();
        }

        return new ChatMessage(
            role: $this->role,
            content: $this->content,
            toolCalls: $toolCalls,
            toolCallId: $this->tool_call_id,
            images: $images,
            thinking: $this->thinking,
            name: $this->name,
            tokenCount: $this->token_count,
            countedAt: $this->counted_at?->toISOString(),
        );
    }

    /**
     * Create a message from a ChatMessage DTO.
     *
     * @param  array<string, mixed>  $attributes  Additional attributes
     */
    public static function fromChatMessage(ChatMessage $dto, array $attributes = []): self
    {
        return new self(array_merge([
            'role' => $dto->role,
            'content' => $dto->content,
            'thinking' => $dto->thinking,
            'name' => $dto->name,
            'tool_call_id' => $dto->toolCallId,
            'token_count' => $dto->tokenCount,
            'counted_at' => $dto->countedAt,
        ], $attributes));
    }

    /**
     * Scope to only pinned messages.
     */
    public function scopePinned(Builder $query): Builder
    {
        return $query->where('pinned', true);
    }

    /**
     * Scope to exclude summarized messages.
     */
    public function scopeNotSummarized(Builder $query): Builder
    {
        return $query->where('summarized', false);
    }

    /**
     * Scope to exclude synthetic messages.
     */
    public function scopeNotSynthetic(Builder $query): Builder
    {
        return $query->where('is_synthetic', false);
    }

    /**
     * Scope to only synthetic messages.
     */
    public function scopeSynthetic(Builder $query): Builder
    {
        return $query->where('is_synthetic', true);
    }

    /**
     * Get the summary that includes this message.
     */
    public function summary(): BelongsTo
    {
        return $this->belongsTo(ConversationSummary::class, 'summary_id');
    }

    /**
     * Get the token count, estimating if not cached.
     */
    public function getTokenCount(TokenEstimator $estimator): int
    {
        if ($this->token_count !== null) {
            return $this->token_count;
        }

        $count = $estimator->estimate($this->toChatMessage());

        if ($this->exists && config('ai.token_estimation.cache_on_message', true)) {
            $this->update(['token_count' => $count, 'counted_at' => now()]);
        }

        return $count;
    }
}
