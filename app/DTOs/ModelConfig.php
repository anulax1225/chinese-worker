<?php

namespace App\DTOs;

readonly class ModelConfig
{
    public function __construct(
        public ?string $model = null,
        public ?float $temperature = null,
        public ?int $maxTokens = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public ?int $contextLength = null,
        public ?float $frequencyPenalty = null,
        public ?float $presencePenalty = null,
        public ?int $timeout = null,
        public ?array $stopSequences = null,
    ) {}

    /**
     * Create from array (e.g., from database JSON column).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            model: $data['model'] ?? null,
            temperature: isset($data['temperature']) ? (float) $data['temperature'] : null,
            maxTokens: isset($data['max_tokens']) ? (int) $data['max_tokens'] : null,
            topP: isset($data['top_p']) ? (float) $data['top_p'] : null,
            topK: isset($data['top_k']) ? (int) $data['top_k'] : null,
            contextLength: isset($data['context_length']) ? (int) $data['context_length'] : null,
            frequencyPenalty: isset($data['frequency_penalty']) ? (float) $data['frequency_penalty'] : null,
            presencePenalty: isset($data['presence_penalty']) ? (float) $data['presence_penalty'] : null,
            timeout: isset($data['timeout']) ? (int) $data['timeout'] : null,
            stopSequences: $data['stop_sequences'] ?? null,
        );
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'context_length' => $this->contextLength,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'timeout' => $this->timeout,
            'stop_sequences' => $this->stopSequences,
        ], fn ($v) => $v !== null);
    }

    /**
     * Merge with another config, with the other config taking precedence.
     */
    public function merge(self $other): self
    {
        return new self(
            model: $other->model ?? $this->model,
            temperature: $other->temperature ?? $this->temperature,
            maxTokens: $other->maxTokens ?? $this->maxTokens,
            topP: $other->topP ?? $this->topP,
            topK: $other->topK ?? $this->topK,
            contextLength: $other->contextLength ?? $this->contextLength,
            frequencyPenalty: $other->frequencyPenalty ?? $this->frequencyPenalty,
            presencePenalty: $other->presencePenalty ?? $this->presencePenalty,
            timeout: $other->timeout ?? $this->timeout,
            stopSequences: $other->stopSequences ?? $this->stopSequences,
        );
    }

    /**
     * Check if config has any values set.
     */
    public function isEmpty(): bool
    {
        return $this->model === null
            && $this->temperature === null
            && $this->maxTokens === null
            && $this->topP === null
            && $this->topK === null
            && $this->contextLength === null
            && $this->frequencyPenalty === null
            && $this->presencePenalty === null
            && $this->timeout === null
            && $this->stopSequences === null;
    }
}
