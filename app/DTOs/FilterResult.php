<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class FilterResult
{
    /**
     * @param  array<int, ChatMessage>  $messages
     * @param  array<int, string>  $removedMessageIds
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public array $messages,
        public int $originalCount,
        public int $filteredCount,
        public array $removedMessageIds,
        public string $strategyUsed,
        public float $durationMs,
        public ?array $metadata = null,
    ) {}

    /**
     * Check if any messages were removed.
     */
    public function hasRemovedMessages(): bool
    {
        return $this->originalCount > $this->filteredCount;
    }

    /**
     * Get the number of messages removed.
     */
    public function getRemovedCount(): int
    {
        return $this->originalCount - $this->filteredCount;
    }

    /**
     * Create a no-op result (no filtering applied).
     *
     * @param  array<int, ChatMessage>  $messages
     */
    public static function noOp(array $messages, string $strategy = 'noop'): self
    {
        return new self(
            messages: $messages,
            originalCount: count($messages),
            filteredCount: count($messages),
            removedMessageIds: [],
            strategyUsed: $strategy,
            durationMs: 0.0,
        );
    }

    /**
     * Convert to array for API responses.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [
            'original_count' => $this->originalCount,
            'filtered_count' => $this->filteredCount,
            'removed_count' => $this->getRemovedCount(),
            'removed_message_ids' => $this->removedMessageIds,
            'strategy_used' => $this->strategyUsed,
            'duration_ms' => round($this->durationMs, 2),
        ];

        if ($this->metadata !== null) {
            $result['metadata'] = $this->metadata;
        }

        return $result;
    }
}
