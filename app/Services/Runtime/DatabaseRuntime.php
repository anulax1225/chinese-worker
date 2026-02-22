<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Contracts\ConversationRuntime;
use App\DTOs\ChatMessage;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;

class DatabaseRuntime implements ConversationRuntime
{
    public function __construct(
        protected Conversation $conversation
    ) {}

    /**
     * Expose the underlying model for backward-compatible services.
     */
    public function getConversation(): Conversation
    {
        return $this->conversation;
    }

    public function getId(): int|string
    {
        return $this->conversation->id;
    }

    public function getAgent(): Agent
    {
        return $this->conversation->agent;
    }

    public function getUser(): ?User
    {
        return $this->conversation->user;
    }

    public function getClientToolSchemas(): array
    {
        return $this->conversation->client_tool_schemas ?? [];
    }

    public function hasDocuments(): bool
    {
        return $this->conversation->hasDocuments();
    }

    public function isPersistent(): bool
    {
        return true;
    }

    public function getContextLimit(): ?int
    {
        return $this->conversation->context_limit;
    }

    public function setContextLimit(int $limit): void
    {
        $this->conversation->update(['context_limit' => $limit]);
    }

    public function getTurnCount(): int
    {
        return $this->conversation->turn_count;
    }

    public function getRequestTurnCount(): int
    {
        return $this->conversation->getRequestTurnCount();
    }

    public function getMaxTurns(): int
    {
        return $this->conversation->getMaxTurns();
    }

    public function incrementTurn(): void
    {
        $this->conversation->incrementTurn();
    }

    public function incrementRequestTurn(): void
    {
        $this->conversation->incrementRequestTurn();
    }

    public function resetRequestTurnCount(): void
    {
        $this->conversation->resetRequestTurnCount();
    }

    public function addMessage(ChatMessage $message): void
    {
        $this->conversation->addMessage($message);
    }

    public function getMessages(): array
    {
        return $this->conversation->getMessages();
    }

    public function getMessageCount(): int
    {
        return $this->conversation->conversationMessages()->count();
    }

    public function addTokenUsage(int $promptTokens, int $completionTokens): void
    {
        $this->conversation->addTokenUsage($promptTokens, $completionTokens);
    }

    public function getTotalTokens(): int
    {
        return $this->conversation->total_tokens ?? 0;
    }

    public function getPromptTokens(): int
    {
        return $this->conversation->prompt_tokens ?? 0;
    }

    public function getCompletionTokens(): int
    {
        return $this->conversation->completion_tokens ?? 0;
    }

    public function isApproachingContextLimit(float $threshold = 0.8): bool
    {
        return $this->conversation->isApproachingContextLimit($threshold);
    }

    public function markAsCompleted(): void
    {
        $this->conversation->markAsCompleted();
    }

    public function markAsCancelled(): void
    {
        $this->conversation->markAsCancelled();
    }

    public function markAsFailed(): void
    {
        $this->conversation->update(['status' => 'failed']);
    }

    public function isCancelled(): bool
    {
        return $this->conversation->isCancelled();
    }

    public function storeSnapshot(string $systemPrompt, ?array $context, ?array $modelConfig): void
    {
        $this->conversation->update([
            'system_prompt_snapshot' => $systemPrompt,
            'prompt_context_snapshot' => $context,
            'model_config_snapshot' => $modelConfig,
        ]);
    }

    public function setPendingToolRequest(array $toolRequest): void
    {
        $this->conversation->update([
            'status' => 'paused',
            'waiting_for' => 'tool_result',
            'pending_tool_request' => $toolRequest,
        ]);
    }

    public function refresh(): void
    {
        $this->conversation->refresh();
    }
}
