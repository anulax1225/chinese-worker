<?php

declare(strict_types=1);

namespace App\Services\Runtime;

use App\Contracts\ConversationRuntime;
use App\DTOs\ChatMessage;
use App\Models\Agent;
use App\Models\User;
use Illuminate\Support\Str;

class InMemoryRuntime implements ConversationRuntime
{
    protected string $id;

    protected int $turnCount = 0;

    protected int $requestTurnCount = 0;

    protected int $promptTokens = 0;

    protected int $completionTokens = 0;

    protected int $totalTokens = 0;

    protected ?int $contextLimit = null;

    protected string $status = 'active';

    protected ?array $pendingToolRequest = null;

    /** @var array<ChatMessage> */
    protected array $messages = [];

    /**
     * @param  array<string, string>  $contextVariables  Custom variables for system prompt template rendering.
     */
    public function __construct(
        protected Agent $agent,
        protected ?User $user = null,
        protected array $clientToolSchemas = [],
        protected int $maxTurns = 25,
        protected array $contextVariables = [],
    ) {
        $this->id = 'ghost_'.Str::uuid()->toString();
        $this->maxTurns = $maxTurns ?: (int) config('agent.max_turns', 25);
    }

    public function getId(): int|string
    {
        return $this->id;
    }

    public function getAgent(): Agent
    {
        return $this->agent;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    /**
     * @return array<string, string>
     */
    public function getContextVariables(): array
    {
        return $this->contextVariables;
    }

    public function getClientToolSchemas(): array
    {
        return $this->clientToolSchemas;
    }

    public function hasDocuments(): bool
    {
        return false;
    }

    public function isPersistent(): bool
    {
        return false;
    }

    public function getContextLimit(): ?int
    {
        return $this->contextLimit;
    }

    public function setContextLimit(int $limit): void
    {
        $this->contextLimit = $limit;
    }

    public function getTurnCount(): int
    {
        return $this->turnCount;
    }

    public function getRequestTurnCount(): int
    {
        return $this->requestTurnCount;
    }

    public function getMaxTurns(): int
    {
        return $this->maxTurns;
    }

    public function incrementTurn(): void
    {
        $this->turnCount++;
    }

    public function incrementRequestTurn(): void
    {
        $this->requestTurnCount++;
    }

    public function resetRequestTurnCount(): void
    {
        $this->requestTurnCount = 0;
    }

    public function addMessage(ChatMessage $message): void
    {
        $this->messages[] = $message;
    }

    /** @return array<ChatMessage> */
    public function getMessages(): array
    {
        return $this->messages;
    }

    public function getMessageCount(): int
    {
        return count($this->messages);
    }

    public function addTokenUsage(int $promptTokens, int $completionTokens): void
    {
        $this->promptTokens += $promptTokens;
        $this->completionTokens += $completionTokens;
        $this->totalTokens += ($promptTokens + $completionTokens);
    }

    public function getTotalTokens(): int
    {
        return $this->totalTokens;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function isApproachingContextLimit(float $threshold = 0.8): bool
    {
        if ($this->contextLimit === null || $this->contextLimit === 0) {
            return false;
        }

        return ($this->totalTokens / $this->contextLimit) >= $threshold;
    }

    public function markAsCompleted(): void
    {
        $this->status = 'completed';
    }

    public function markAsCancelled(): void
    {
        $this->status = 'cancelled';
    }

    public function markAsFailed(): void
    {
        $this->status = 'failed';
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function storeSnapshot(string $systemPrompt, ?array $context, ?array $modelConfig): void
    {
        // No-op for in-memory runtime
    }

    public function setPendingToolRequest(array $toolRequest): void
    {
        $this->pendingToolRequest = $toolRequest;
        $this->status = 'paused';
    }

    public function getPendingToolRequest(): ?array
    {
        return $this->pendingToolRequest;
    }

    public function refresh(): void
    {
        // No-op — no DB to refresh from
    }

    /**
     * Export stats for API response.
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return [
            'turns' => $this->turnCount,
            'tokens' => $this->totalTokens,
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
        ];
    }
}
