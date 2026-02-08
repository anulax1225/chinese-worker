<?php

namespace App\DTOs\Document;

class Section
{
    public function __construct(
        public readonly ?string $title,
        public readonly int $level,
        public readonly string $content,
        public readonly int $startOffset,
        public readonly int $endOffset,
    ) {}

    /**
     * Get the word count for this section.
     */
    public function wordCount(): int
    {
        return str_word_count($this->content);
    }

    /**
     * Get the character count for this section.
     */
    public function characterCount(): int
    {
        return mb_strlen($this->content);
    }

    /**
     * Convert to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'level' => $this->level,
            'content' => $this->content,
            'start_offset' => $this->startOffset,
            'end_offset' => $this->endOffset,
            'word_count' => $this->wordCount(),
            'character_count' => $this->characterCount(),
        ];
    }
}
