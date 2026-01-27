<?php

namespace App\DTOs;

class ToolResult
{
    /**
     * Create a new tool result instance.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $output,
        public readonly ?string $error = null,
        public readonly array $metadata = []
    ) {}

    /**
     * Create a successful result.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function success(string $output, array $metadata = []): self
    {
        return new self(
            success: true,
            output: $output,
            error: null,
            metadata: $metadata
        );
    }

    /**
     * Create a failed result.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function failure(string $error, array $metadata = []): self
    {
        return new self(
            success: false,
            output: '',
            error: $error,
            metadata: $metadata
        );
    }

    /**
     * Convert to string for the AI response.
     */
    public function toString(): string
    {
        if ($this->success) {
            return $this->output;
        }

        return "Error: {$this->error}";
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
            'output' => $this->output,
            'error' => $this->error,
            'metadata' => $this->metadata,
        ];
    }
}
