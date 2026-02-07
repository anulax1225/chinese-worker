<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class TokenUsage
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public ?int $contextLimit = null,
    ) {}

    /**
     * Get the percentage of context limit used.
     */
    public function getUsagePercentage(): ?float
    {
        if ($this->contextLimit === null || $this->contextLimit === 0) {
            return null;
        }

        return ($this->totalTokens / $this->contextLimit) * 100;
    }

    /**
     * Check if approaching the context limit.
     */
    public function isApproachingLimit(float $threshold = 0.8): bool
    {
        $percentage = $this->getUsagePercentage();

        return $percentage !== null && $percentage >= ($threshold * 100);
    }

    /**
     * Get remaining tokens before hitting context limit.
     */
    public function getRemainingTokens(): ?int
    {
        if ($this->contextLimit === null) {
            return null;
        }

        return max(0, $this->contextLimit - $this->totalTokens);
    }

    /**
     * Convert to array for API responses.
     */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'context_limit' => $this->contextLimit,
            'remaining_tokens' => $this->getRemainingTokens(),
            'usage_percentage' => $this->contextLimit
                ? round($this->getUsagePercentage(), 1)
                : null,
        ];
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            promptTokens: $data['prompt_tokens'] ?? 0,
            completionTokens: $data['completion_tokens'] ?? 0,
            totalTokens: $data['total_tokens'] ?? 0,
            contextLimit: $data['context_limit'] ?? null,
        );
    }
}
