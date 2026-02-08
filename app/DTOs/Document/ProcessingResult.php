<?php

namespace App\DTOs\Document;

use App\Enums\DocumentStageType;

class ProcessingResult
{
    /**
     * Create a new processing result instance.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly DocumentStageType $stage,
        public readonly ?string $content = null,
        public readonly ?string $error = null,
        public readonly float $durationSeconds = 0.0,
        public readonly array $metadata = [],
    ) {}

    /**
     * Create a successful processing result.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function success(
        DocumentStageType $stage,
        string $content,
        float $durationSeconds = 0.0,
        array $metadata = [],
    ): self {
        return new self(
            success: true,
            stage: $stage,
            content: $content,
            error: null,
            durationSeconds: $durationSeconds,
            metadata: $metadata,
        );
    }

    /**
     * Create a failed processing result.
     */
    public static function failure(DocumentStageType $stage, string $error, float $durationSeconds = 0.0): self
    {
        return new self(
            success: false,
            stage: $stage,
            content: null,
            error: $error,
            durationSeconds: $durationSeconds,
            metadata: [],
        );
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'stage' => $this->stage->value,
            'content' => $this->content,
            'error' => $this->error,
            'duration_seconds' => $this->durationSeconds,
            'metadata' => $this->metadata,
        ];
    }
}
