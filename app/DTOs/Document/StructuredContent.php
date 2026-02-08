<?php

namespace App\DTOs\Document;

class StructuredContent
{
    /**
     * @param  array<Section>  $sections
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $text,
        public readonly array $sections = [],
        public readonly array $metadata = [],
    ) {}

    /**
     * Get the number of sections.
     */
    public function sectionCount(): int
    {
        return count($this->sections);
    }

    /**
     * Check if content has sections.
     */
    public function hasSections(): bool
    {
        return count($this->sections) > 0;
    }

    /**
     * Get sections by level.
     *
     * @return array<Section>
     */
    public function getSectionsByLevel(int $level): array
    {
        return array_filter(
            $this->sections,
            fn (Section $section) => $section->level === $level
        );
    }

    /**
     * Get section titles.
     *
     * @return array<string>
     */
    public function getSectionTitles(): array
    {
        return array_filter(
            array_map(
                fn (Section $section) => $section->title,
                $this->sections
            )
        );
    }

    /**
     * Get a flattened table of contents.
     *
     * @return array<array{title: string|null, level: int}>
     */
    public function getTableOfContents(): array
    {
        return array_map(
            fn (Section $section) => [
                'title' => $section->title,
                'level' => $section->level,
            ],
            $this->sections
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
            'text' => $this->text,
            'sections' => array_map(
                fn (Section $section) => $section->toArray(),
                $this->sections
            ),
            'section_count' => $this->sectionCount(),
            'metadata' => $this->metadata,
        ];
    }
}
