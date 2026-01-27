<?php

namespace App\DTOs;

class AgentLoopResult
{
    public const STATUS_COMPLETED = 'completed';

    public const STATUS_MAX_TURNS = 'max_turns';

    public const STATUS_TOOL_ERROR = 'tool_error';

    public const STATUS_ERROR = 'error';

    /**
     * Create a new agent loop result instance.
     *
     * @param  array<ChatMessage>  $conversationHistory
     * @param  array<array<string, mixed>>  $toolResults
     */
    public function __construct(
        public readonly string $status,
        public readonly ?AIResponse $finalResponse,
        public readonly array $conversationHistory,
        public readonly int $turnsUsed,
        public readonly array $toolResults = [],
        public readonly ?string $error = null
    ) {}

    /**
     * Create a completed result.
     *
     * @param  array<ChatMessage>  $history
     * @param  array<array<string, mixed>>  $toolResults
     */
    public static function completed(AIResponse $response, array $history, int $turns, array $toolResults = []): self
    {
        return new self(
            status: self::STATUS_COMPLETED,
            finalResponse: $response,
            conversationHistory: $history,
            turnsUsed: $turns,
            toolResults: $toolResults,
            error: null
        );
    }

    /**
     * Create a max turns reached result.
     *
     * @param  array<ChatMessage>  $history
     * @param  array<array<string, mixed>>  $toolResults
     */
    public static function maxTurnsReached(?AIResponse $lastResponse, array $history, int $turns, array $toolResults = []): self
    {
        return new self(
            status: self::STATUS_MAX_TURNS,
            finalResponse: $lastResponse,
            conversationHistory: $history,
            turnsUsed: $turns,
            toolResults: $toolResults,
            error: 'Maximum turns reached'
        );
    }

    /**
     * Create a tool error result.
     *
     * @param  array<ChatMessage>  $history
     * @param  array<array<string, mixed>>  $toolResults
     */
    public static function toolError(string $error, ?AIResponse $lastResponse, array $history, int $turns, array $toolResults = []): self
    {
        return new self(
            status: self::STATUS_TOOL_ERROR,
            finalResponse: $lastResponse,
            conversationHistory: $history,
            turnsUsed: $turns,
            toolResults: $toolResults,
            error: $error
        );
    }

    /**
     * Create a general error result.
     *
     * @param  array<ChatMessage>  $history
     */
    public static function error(string $error, array $history = [], int $turns = 0): self
    {
        return new self(
            status: self::STATUS_ERROR,
            finalResponse: null,
            conversationHistory: $history,
            turnsUsed: $turns,
            toolResults: [],
            error: $error
        );
    }

    /**
     * Check if the loop completed successfully.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Get the final content from the response.
     */
    public function getContent(): string
    {
        return $this->finalResponse?->content ?? '';
    }

    /**
     * Get the total tokens used across all turns.
     */
    public function getTotalTokens(): int
    {
        return $this->finalResponse?->tokensUsed ?? 0;
    }

    /**
     * Convert to array for storage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'content' => $this->getContent(),
            'turns_used' => $this->turnsUsed,
            'tool_results' => $this->toolResults,
            'error' => $this->error,
            'final_response' => $this->finalResponse?->toArray(),
        ];
    }
}
