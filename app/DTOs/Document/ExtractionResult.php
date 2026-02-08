<?php

namespace App\DTOs\Document;

class ExtractionResult
{
    /**
     * Create a new extraction result instance.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string>  $warnings
     */
    public function __construct(
        public readonly bool $success,
        public readonly string $text,
        public readonly ?string $error = null,
        public readonly array $metadata = [],
        public readonly array $warnings = [],
    ) {}

    /**
     * Create a successful extraction result.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string>  $warnings
     */
    public static function success(string $text, array $metadata = [], array $warnings = []): self
    {
        return new self(
            success: true,
            text: $text,
            error: null,
            metadata: $metadata,
            warnings: $warnings,
        );
    }

    /**
     * Create a failed extraction result.
     */
    public static function failure(string $error): self
    {
        return new self(
            success: false,
            text: '',
            error: $error,
            metadata: [],
            warnings: [],
        );
    }

    /**
     * Get the word count of extracted text.
     */
    public function wordCount(): int
    {
        return str_word_count($this->text);
    }

    /**
     * Get the character count of extracted text.
     */
    public function characterCount(): int
    {
        return mb_strlen($this->text);
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
            'text' => $this->text,
            'error' => $this->error,
            'metadata' => $this->metadata,
            'warnings' => $this->warnings,
            'word_count' => $this->wordCount(),
            'character_count' => $this->characterCount(),
        ];
    }
}
