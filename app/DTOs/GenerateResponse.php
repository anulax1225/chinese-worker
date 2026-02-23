<?php

namespace App\DTOs;

readonly class GenerateResponse
{
    /**
     * Create a new text generation response.
     *
     * @param  string  $content  The generated text
     * @param  string  $model  Model used for generation
     * @param  bool  $done  Whether generation is complete
     * @param  string|null  $doneReason  Reason generation stopped
     * @param  string|null  $thinking  Thinking output (when think mode enabled)
     * @param  int|null  $totalDuration  Total time in nanoseconds
     * @param  int|null  $loadDuration  Model load time in nanoseconds
     * @param  int|null  $promptEvalCount  Number of input tokens
     * @param  int|null  $promptEvalDuration  Prompt evaluation time in nanoseconds
     * @param  int|null  $evalCount  Number of output tokens
     * @param  int|null  $evalDuration  Generation time in nanoseconds
     * @param  array<array<string, mixed>>|null  $logprobs  Log probabilities
     * @param  string|null  $createdAt  ISO 8601 timestamp
     */
    public function __construct(
        public string $content,
        public string $model,
        public bool $done,
        public ?string $doneReason = null,
        public ?string $thinking = null,
        public ?int $totalDuration = null,
        public ?int $loadDuration = null,
        public ?int $promptEvalCount = null,
        public ?int $promptEvalDuration = null,
        public ?int $evalCount = null,
        public ?int $evalDuration = null,
        public ?array $logprobs = null,
        public ?string $createdAt = null,
    ) {}

    /**
     * Create from an Ollama /api/generate response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromOllamaResponse(array $data): self
    {
        return new self(
            content: $data['response'] ?? '',
            model: $data['model'] ?? '',
            done: $data['done'] ?? true,
            doneReason: $data['done_reason'] ?? null,
            thinking: $data['thinking'] ?? null,
            totalDuration: $data['total_duration'] ?? null,
            loadDuration: $data['load_duration'] ?? null,
            promptEvalCount: $data['prompt_eval_count'] ?? null,
            promptEvalDuration: $data['prompt_eval_duration'] ?? null,
            evalCount: $data['eval_count'] ?? null,
            evalDuration: $data['eval_duration'] ?? null,
            logprobs: $data['logprobs'] ?? null,
            createdAt: $data['created_at'] ?? null,
        );
    }

    /**
     * Create from a vLLM /v1/completions response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromVLLMResponse(array $data): self
    {
        $choices = $data['choices'] ?? [];
        $choice = $choices[0] ?? [];
        $usage = $data['usage'] ?? [];

        return new self(
            content: $choice['text'] ?? '',
            model: $data['model'] ?? '',
            done: true,
            doneReason: $choice['finish_reason'] ?? 'stop',
            thinking: null,
            totalDuration: null,
            loadDuration: null,
            promptEvalCount: $usage['prompt_tokens'] ?? null,
            promptEvalDuration: null,
            evalCount: $usage['completion_tokens'] ?? null,
            evalDuration: null,
            logprobs: $choice['logprobs'] ?? null,
            createdAt: isset($data['created'])
                ? date('c', $data['created'])
                : null,
        );
    }

    /**
     * Get the total number of tokens used.
     */
    public function getTokensUsed(): int
    {
        return ($this->promptEvalCount ?? 0) + ($this->evalCount ?? 0);
    }

    /**
     * Get the generation speed in tokens per second.
     */
    public function getTokensPerSecond(): ?float
    {
        if ($this->evalCount === null || $this->evalDuration === null || $this->evalDuration === 0) {
            return null;
        }

        // Convert nanoseconds to seconds
        $seconds = $this->evalDuration / 1_000_000_000;

        return $this->evalCount / $seconds;
    }

    /**
     * Get the total duration in milliseconds.
     */
    public function getTotalDurationMs(): ?float
    {
        if ($this->totalDuration === null) {
            return null;
        }

        return $this->totalDuration / 1_000_000;
    }

    /**
     * Convert to an AIResponse for compatibility with existing code.
     */
    public function toAIResponse(): AIResponse
    {
        return new AIResponse(
            content: $this->content,
            model: $this->model,
            tokensUsed: $this->getTokensUsed(),
            finishReason: $this->doneReason ?? ($this->done ? 'stop' : 'incomplete'),
            toolCalls: [],
            metadata: [
                'total_duration' => $this->totalDuration,
                'load_duration' => $this->loadDuration,
                'prompt_eval_count' => $this->promptEvalCount,
                'prompt_eval_duration' => $this->promptEvalDuration,
                'eval_count' => $this->evalCount,
                'eval_duration' => $this->evalDuration,
                'tokens_per_second' => $this->getTokensPerSecond(),
                'created_at' => $this->createdAt,
            ],
            thinking: $this->thinking
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
            'content' => $this->content,
            'model' => $this->model,
            'done' => $this->done,
            'done_reason' => $this->doneReason,
            'thinking' => $this->thinking,
            'total_duration' => $this->totalDuration,
            'load_duration' => $this->loadDuration,
            'prompt_eval_count' => $this->promptEvalCount,
            'prompt_eval_duration' => $this->promptEvalDuration,
            'eval_count' => $this->evalCount,
            'eval_duration' => $this->evalDuration,
            'logprobs' => $this->logprobs,
            'created_at' => $this->createdAt,
            'tokens_used' => $this->getTokensUsed(),
            'tokens_per_second' => $this->getTokensPerSecond(),
        ], fn ($v) => $v !== null);
    }
}
