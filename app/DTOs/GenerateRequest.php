<?php

namespace App\DTOs;

use InvalidArgumentException;

readonly class GenerateRequest
{
    /**
     * Create a new text generation request.
     *
     * @param  string  $prompt  The prompt to generate from
     * @param  string|null  $suffix  Text after prompt for fill-in-the-middle
     * @param  array<string>|null  $images  Base64-encoded images for vision models
     * @param  string|array<string, mixed>|null  $format  'json' or JSON schema for structured output
     * @param  string|null  $system  System prompt
     * @param  bool|string|null  $think  Enable thinking mode (true/false or 'high'/'medium'/'low')
     * @param  bool  $raw  Skip prompt templating
     * @param  string|int|null  $keepAlive  Model keep-alive duration (e.g., '5m' or 0)
     * @param  int|null  $maxTokens  Maximum tokens to generate (num_predict)
     * @param  float|null  $temperature  Randomness (0-2)
     * @param  float|null  $topP  Nucleus sampling threshold (0-1)
     * @param  int|null  $topK  Top-k sampling limit
     * @param  float|null  $minP  Minimum probability threshold
     * @param  int|null  $seed  Random seed for reproducibility
     * @param  array<string>|string|null  $stop  Stop sequences
     * @param  int|null  $contextLength  Context window size (num_ctx)
     * @param  bool|null  $logprobs  Return log probabilities
     * @param  int|null  $topLogprobs  Number of top logprobs to return
     */
    public function __construct(
        public string $prompt,
        public ?string $suffix = null,
        public ?array $images = null,
        public string|array|null $format = null,
        public ?string $system = null,
        public bool|string|null $think = null,
        public bool $raw = false,
        public string|int|null $keepAlive = null,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public ?float $topP = null,
        public ?int $topK = null,
        public ?float $minP = null,
        public ?int $seed = null,
        public array|string|null $stop = null,
        public ?int $contextLength = null,
        public ?bool $logprobs = null,
        public ?int $topLogprobs = null,
    ) {}

    /**
     * Check if the request is valid.
     */
    public function isValid(): bool
    {
        return ! empty(trim($this->prompt));
    }

    /**
     * Validate the request and throw if invalid.
     *
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if (! $this->isValid()) {
            throw new InvalidArgumentException('GenerateRequest requires a non-empty prompt');
        }

        if ($this->think !== null && ! is_bool($this->think) && ! in_array($this->think, ['high', 'medium', 'low'], true)) {
            throw new InvalidArgumentException('think must be a boolean or one of: high, medium, low');
        }

        if ($this->temperature !== null && ($this->temperature < 0 || $this->temperature > 2)) {
            throw new InvalidArgumentException('temperature must be between 0 and 2');
        }

        if ($this->topP !== null && ($this->topP < 0 || $this->topP > 1)) {
            throw new InvalidArgumentException('topP must be between 0 and 1');
        }

        if ($this->minP !== null && ($this->minP < 0 || $this->minP > 1)) {
            throw new InvalidArgumentException('minP must be between 0 and 1');
        }
    }

    /**
     * Convert to Ollama /api/generate payload.
     *
     * @return array<string, mixed>
     */
    public function toOllamaPayload(string $model, bool $stream = true): array
    {
        $payload = [
            'model' => $model,
            'prompt' => $this->prompt,
            'stream' => $stream,
        ];

        if ($this->suffix !== null) {
            $payload['suffix'] = $this->suffix;
        }

        if ($this->images !== null && count($this->images) > 0) {
            $payload['images'] = $this->images;
        }

        if ($this->format !== null) {
            $payload['format'] = $this->format;
        }

        if ($this->system !== null) {
            $payload['system'] = $this->system;
        }

        if ($this->think !== null) {
            $payload['think'] = $this->think;
        }

        if ($this->raw) {
            $payload['raw'] = true;
        }

        if ($this->keepAlive !== null) {
            $payload['keep_alive'] = $this->keepAlive;
        }

        if ($this->logprobs !== null) {
            $payload['logprobs'] = $this->logprobs;
        }

        if ($this->topLogprobs !== null) {
            $payload['top_logprobs'] = $this->topLogprobs;
        }

        if ($this->stop !== null) {
            $payload['stop'] = is_array($this->stop) ? $this->stop : [$this->stop];
        }

        $options = $this->buildOptions();
        if (! empty($options)) {
            $payload['options'] = $options;
        }

        return $payload;
    }

    /**
     * Build Ollama options from generation parameters.
     *
     * @return array<string, mixed>
     */
    protected function buildOptions(): array
    {
        $options = [];

        if ($this->maxTokens !== null) {
            $options['num_predict'] = $this->maxTokens;
        }

        if ($this->temperature !== null) {
            $options['temperature'] = $this->temperature;
        }

        if ($this->topP !== null) {
            $options['top_p'] = $this->topP;
        }

        if ($this->topK !== null) {
            $options['top_k'] = $this->topK;
        }

        if ($this->minP !== null) {
            $options['min_p'] = $this->minP;
        }

        if ($this->seed !== null) {
            $options['seed'] = $this->seed;
        }

        // stop is a top-level Ollama parameter, not an option — see toOllamaPayload()

        if ($this->contextLength !== null) {
            $options['num_ctx'] = $this->contextLength;
        }

        return $options;
    }

    /**
     * Convert to array for serialization.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'prompt' => $this->prompt,
            'suffix' => $this->suffix,
            'images' => $this->images,
            'format' => $this->format,
            'system' => $this->system,
            'think' => $this->think,
            'raw' => $this->raw ?: null,
            'keep_alive' => $this->keepAlive,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'top_p' => $this->topP,
            'top_k' => $this->topK,
            'min_p' => $this->minP,
            'seed' => $this->seed,
            'stop' => $this->stop,
            'context_length' => $this->contextLength,
            'logprobs' => $this->logprobs,
            'top_logprobs' => $this->topLogprobs,
        ], fn ($v) => $v !== null);
    }
}
