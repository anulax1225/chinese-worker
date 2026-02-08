<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Agent;
use App\Models\Conversation;

readonly class FilterContext
{
    /**
     * @param  array<int, ChatMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public array $messages,
        public int $contextLimit,
        public int $maxOutputTokens,
        public int $toolDefinitionTokens,
        public array $options,
        public ?Agent $agent = null,
        public ?Conversation $conversation = null,
    ) {}

    /**
     * Get available budget for message history.
     */
    public function getAvailableBudget(): int
    {
        return max(0, $this->contextLimit - $this->maxOutputTokens - $this->toolDefinitionTokens);
    }

    /**
     * Create from a conversation with default values.
     *
     * @param  array<string, mixed>  $options
     */
    public static function fromConversation(
        Conversation $conversation,
        array $options = [],
        int $maxOutputTokens = 4096,
        int $toolDefinitionTokens = 0,
    ): self {
        return new self(
            messages: $conversation->getMessages(),
            contextLimit: $conversation->context_limit ?? 128000,
            maxOutputTokens: $maxOutputTokens,
            toolDefinitionTokens: $toolDefinitionTokens,
            options: $options,
            agent: $conversation->agent,
            conversation: $conversation,
        );
    }
}
