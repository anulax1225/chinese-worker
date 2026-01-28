<?php

namespace App\DTOs;

class AIResponse
{
    /**
     * Create a new AI response instance.
     *
     * @param  array<int, ToolCall>  $toolCalls
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly int $tokensUsed,
        public readonly string $finishReason,
        public readonly array $toolCalls = [],
        public readonly array $metadata = [],
        public readonly ?string $thinking = null
    ) {}

    /**
     * Check if the response has tool calls.
     */
    public function hasToolCalls(): bool
    {
        return ! empty($this->toolCalls);
    }

    /**
     * Convert the response to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'content' => $this->content,
            'model' => $this->model,
            'tokens_used' => $this->tokensUsed,
            'finish_reason' => $this->finishReason,
            'tool_calls' => array_map(fn (ToolCall $tc) => $tc->toArray(), $this->toolCalls),
            'metadata' => $this->metadata,
            'thinking' => $this->thinking,
        ];
    }
}
