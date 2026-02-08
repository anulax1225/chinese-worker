<?php

namespace App\Enums;

enum DocumentStageType: string
{
    case Extracted = 'extracted';
    case Cleaned = 'cleaned';
    case Normalized = 'normalized';
    case Chunked = 'chunked';

    /**
     * Get the processing order.
     */
    public function order(): int
    {
        return match ($this) {
            self::Extracted => 1,
            self::Cleaned => 2,
            self::Normalized => 3,
            self::Chunked => 4,
        };
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Extracted => 'Extracted Text',
            self::Cleaned => 'Cleaned Content',
            self::Normalized => 'Normalized Structure',
            self::Chunked => 'Chunked Content',
        };
    }

    /**
     * Get the corresponding document status for this stage.
     */
    public function correspondingStatus(): DocumentStatus
    {
        return match ($this) {
            self::Extracted => DocumentStatus::Extracting,
            self::Cleaned => DocumentStatus::Cleaning,
            self::Normalized => DocumentStatus::Normalizing,
            self::Chunked => DocumentStatus::Chunking,
        };
    }
}
