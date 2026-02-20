<?php

namespace App\Enums;

enum EmbeddingStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Check if the embedding is currently being processed.
     */
    public function isProcessing(): bool
    {
        return $this === self::Processing;
    }

    /**
     * Check if the embedding is complete.
     */
    public function isComplete(): bool
    {
        return $this === self::Completed;
    }

    /**
     * Check if the embedding processing failed.
     */
    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Check if the embedding is still pending.
     */
    public function isPending(): bool
    {
        return $this === self::Pending;
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }
}
