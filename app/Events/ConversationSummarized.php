<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class ConversationSummarized
{
    use Dispatchable;

    public function __construct(
        public int $conversationId,
        public string $summaryId,
        public int $summarizedMessageCount,
        public int $originalTokenCount,
        public int $summaryTokenCount,
        public float $compressionRatio,
        public string $backend,
        public float $durationMs,
    ) {}

    /**
     * Get the number of tokens saved by summarization.
     */
    public function getTokensSaved(): int
    {
        return max(0, $this->originalTokenCount - $this->summaryTokenCount);
    }

    /**
     * Get the compression percentage (how much smaller the summary is).
     */
    public function getCompressionPercentage(): float
    {
        if ($this->originalTokenCount === 0) {
            return 0.0;
        }

        return (1 - $this->compressionRatio) * 100;
    }
}
