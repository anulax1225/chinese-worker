<?php

namespace App\DTOs;

class AIResponse
{
    /**
     * Create a new AI response instance.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $model,
        public readonly int $tokensUsed,
        public readonly string $finishReason,
        public readonly array $metadata = []
    ) {}

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
            'metadata' => $this->metadata,
        ];
    }
}
