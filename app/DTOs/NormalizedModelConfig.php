<?php

namespace App\DTOs;

readonly class NormalizedModelConfig
{
    /**
     * @param  array<string>  $validationWarnings
     */
    public function __construct(
        public string $model,
        public float $temperature,
        public int $maxTokens,
        public int $contextLength,
        public int $timeout,
        public ?float $topP = null,
        public ?int $topK = null,
        public ?float $frequencyPenalty = null,
        public ?float $presencePenalty = null,
        public ?array $stopSequences = null,
        public array $validationWarnings = [],
    ) {}

    /**
     * Convert to Ollama options format.
     *
     * @return array<string, mixed>
     */
    public function toOllamaOptions(): array
    {
        return array_filter([
            'temperature' => $this->temperature,
            'num_ctx' => $this->contextLength,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'stop' => $this->stopSequences,
        ], fn ($v) => $v !== null);
    }

    /**
     * Convert to Anthropic params format.
     *
     * @return array<string, mixed>
     */
    public function toAnthropicParams(): array
    {
        return array_filter([
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'stop_sequences' => $this->stopSequences,
        ], fn ($v) => $v !== null);
    }

    /**
     * Convert to OpenAI params format.
     *
     * @return array<string, mixed>
     */
    public function toOpenAIParams(): array
    {
        return array_filter([
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'stop' => $this->stopSequences,
        ], fn ($v) => $v !== null);
    }

    /**
     * Convert to array for serialization/logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'context_length' => $this->contextLength,
            'timeout' => $this->timeout,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'stop_sequences' => $this->stopSequences,
            'validation_warnings' => $this->validationWarnings ?: null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Check if there were any validation warnings during normalization.
     */
    public function hasWarnings(): bool
    {
        return count($this->validationWarnings) > 0;
    }
}
