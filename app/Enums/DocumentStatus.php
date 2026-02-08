<?php

namespace App\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Cleaning = 'cleaning';
    case Normalizing = 'normalizing';
    case Chunking = 'chunking';
    case Ready = 'ready';
    case Failed = 'failed';

    /**
     * Check if the document is currently being processed.
     */
    public function isProcessing(): bool
    {
        return in_array($this, [
            self::Extracting,
            self::Cleaning,
            self::Normalizing,
            self::Chunking,
        ]);
    }

    /**
     * Check if the document processing is complete.
     */
    public function isComplete(): bool
    {
        return $this === self::Ready;
    }

    /**
     * Check if the document processing failed.
     */
    public function isFailed(): bool
    {
        return $this === self::Failed;
    }

    /**
     * Get the next status in the processing pipeline.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Pending => self::Extracting,
            self::Extracting => self::Cleaning,
            self::Cleaning => self::Normalizing,
            self::Normalizing => self::Chunking,
            self::Chunking => self::Ready,
            default => null,
        };
    }

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Extracting => 'Extracting Text',
            self::Cleaning => 'Cleaning Content',
            self::Normalizing => 'Normalizing Structure',
            self::Chunking => 'Creating Chunks',
            self::Ready => 'Ready',
            self::Failed => 'Failed',
        };
    }
}
