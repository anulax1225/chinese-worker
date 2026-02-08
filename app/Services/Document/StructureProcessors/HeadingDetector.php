<?php

namespace App\Services\Document\StructureProcessors;

use App\Contracts\StructureProcessorInterface;
use App\DTOs\Document\Section;
use App\DTOs\Document\StructuredContent;

class HeadingDetector implements StructureProcessorInterface
{
    public function getName(): string
    {
        return 'heading_detector';
    }

    public function getDescription(): string
    {
        return 'Detects section headings using various patterns (markdown, all caps, numbered sections).';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function process(StructuredContent $content): StructuredContent
    {
        $text = $content->text;
        $lines = explode("\n", $text);
        $sections = [];
        $currentSection = null;
        $currentContent = [];
        $offset = 0;

        foreach ($lines as $index => $line) {
            $lineLength = mb_strlen($line) + 1; // +1 for newline
            $heading = $this->detectHeading($line, $lines, $index);

            if ($heading !== null) {
                // Save the previous section
                if ($currentSection !== null) {
                    $currentSection = new Section(
                        title: $currentSection->title,
                        level: $currentSection->level,
                        content: trim(implode("\n", $currentContent)),
                        startOffset: $currentSection->startOffset,
                        endOffset: $offset - 1,
                    );
                    $sections[] = $currentSection;
                }

                // Start a new section
                $currentSection = new Section(
                    title: $heading['title'],
                    level: $heading['level'],
                    content: '',
                    startOffset: $offset,
                    endOffset: $offset,
                );
                $currentContent = [];
            } else {
                $currentContent[] = $line;
            }

            $offset += $lineLength;
        }

        // Save the final section
        if ($currentSection !== null) {
            $currentSection = new Section(
                title: $currentSection->title,
                level: $currentSection->level,
                content: trim(implode("\n", $currentContent)),
                startOffset: $currentSection->startOffset,
                endOffset: $offset,
            );
            $sections[] = $currentSection;
        } elseif (! empty($currentContent)) {
            // No headings found - treat entire content as one section
            $sections[] = new Section(
                title: null,
                level: 1,
                content: trim(implode("\n", $currentContent)),
                startOffset: 0,
                endOffset: $offset,
            );
        }

        $metadata = $content->metadata;
        $metadata['headings_detected'] = count(array_filter($sections, fn ($s) => $s->title !== null));

        return new StructuredContent(
            text: $text,
            sections: $sections,
            metadata: $metadata,
        );
    }

    /**
     * Detect if a line is a heading.
     *
     * @param  array<string>  $allLines
     * @return array{title: string, level: int}|null
     */
    protected function detectHeading(string $line, array $allLines, int $index): ?array
    {
        $trimmed = trim($line);

        if (empty($trimmed)) {
            return null;
        }

        // Markdown-style headings (# Heading)
        if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $matches)) {
            return [
                'title' => trim($matches[2]),
                'level' => strlen($matches[1]),
            ];
        }

        // Numbered headings (1. Heading, 1.1 Heading, 1.1.1 Heading)
        if (preg_match('/^(\d+(?:\.\d+)*)\s+([A-Z].{2,})$/', $trimmed, $matches)) {
            $level = substr_count($matches[1], '.') + 1;

            return [
                'title' => $matches[1].' '.trim($matches[2]),
                'level' => min($level, 6),
            ];
        }

        // All caps headings (short lines, all uppercase)
        if (
            mb_strlen($trimmed) >= 3 &&
            mb_strlen($trimmed) <= 80 &&
            preg_match('/^[A-Z][A-Z\s\d\-:]+$/', $trimmed) &&
            preg_match('/[A-Z]/', $trimmed)
        ) {
            // Check if followed by content (not another heading)
            $nextNonEmpty = $this->getNextNonEmptyLine($allLines, $index);
            if ($nextNonEmpty !== null && ! $this->looksLikeHeading($nextNonEmpty)) {
                return [
                    'title' => $trimmed,
                    'level' => 2,
                ];
            }
        }

        // Underlined headings (line followed by === or ---)
        $nextLine = $allLines[$index + 1] ?? '';
        if (preg_match('/^[=]{3,}$/', trim($nextLine))) {
            return [
                'title' => $trimmed,
                'level' => 1,
            ];
        }
        if (preg_match('/^[-]{3,}$/', trim($nextLine))) {
            return [
                'title' => $trimmed,
                'level' => 2,
            ];
        }

        return null;
    }

    /**
     * Get the next non-empty line.
     *
     * @param  array<string>  $lines
     */
    protected function getNextNonEmptyLine(array $lines, int $currentIndex): ?string
    {
        for ($i = $currentIndex + 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (! empty($line)) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Check if a line looks like a heading.
     */
    protected function looksLikeHeading(string $line): bool
    {
        $trimmed = trim($line);

        // Markdown heading
        if (preg_match('/^#{1,6}\s+/', $trimmed)) {
            return true;
        }

        // Numbered heading
        if (preg_match('/^\d+(?:\.\d+)*\s+[A-Z]/', $trimmed)) {
            return true;
        }

        // All caps
        if (mb_strlen($trimmed) <= 80 && preg_match('/^[A-Z][A-Z\s\d\-:]+$/', $trimmed)) {
            return true;
        }

        return false;
    }
}
