<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class ContextFiltered
{
    use Dispatchable;

    /**
     * @param  array<int, string>  $removedMessageIds
     */
    public function __construct(
        public int $conversationId,
        public string $strategyUsed,
        public int $originalCount,
        public int $filteredCount,
        public array $removedMessageIds,
        public float $contextUsageBefore,
        public float $contextUsageAfter,
        public float $durationMs,
    ) {}

    /**
     * Get the number of messages removed.
     */
    public function getRemovedCount(): int
    {
        return $this->originalCount - $this->filteredCount;
    }
}
