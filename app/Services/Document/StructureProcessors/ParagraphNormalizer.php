<?php

namespace App\Services\Document\StructureProcessors;

use App\Contracts\StructureProcessorInterface;
use App\DTOs\Document\Section;
use App\DTOs\Document\StructuredContent;

class ParagraphNormalizer implements StructureProcessorInterface
{
    public function getName(): string
    {
        return 'paragraph_normalizer';
    }

    public function getDescription(): string
    {
        return 'Ensures consistent paragraph breaks using double newlines.';
    }

    public function getPriority(): int
    {
        return 30;
    }

    public function process(StructuredContent $content): StructuredContent
    {
        $text = $content->text;

        // Ensure paragraphs are separated by exactly two newlines
        // First, normalize any 3+ newlines to exactly 2
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        // Detect paragraph boundaries that might be single newlines
        // (but preserve intentional single line breaks in lists, code, etc.)
        $lines = explode("\n", $text);
        $normalizedLines = [];
        $previousWasBlank = false;
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);
            $isBlank = empty($trimmed);
            $isList = $this->isListItem($trimmed);

            if ($isList) {
                $inList = true;
            } elseif ($isBlank) {
                $inList = false;
            }

            if ($isBlank) {
                if (! $previousWasBlank) {
                    $normalizedLines[] = '';
                }
                $previousWasBlank = true;
            } else {
                // Check if this should start a new paragraph
                if (! $previousWasBlank && ! $inList && $this->shouldStartNewParagraph($normalizedLines, $trimmed)) {
                    $normalizedLines[] = '';
                }
                $normalizedLines[] = $line;
                $previousWasBlank = false;
            }
        }

        $text = implode("\n", $normalizedLines);
        $text = trim($text);

        // Update sections
        $sections = $this->updateSections($content->sections, $text);

        return new StructuredContent(
            text: $text,
            sections: $sections,
            metadata: $content->metadata,
        );
    }

    /**
     * Check if a line is a list item.
     */
    protected function isListItem(string $line): bool
    {
        // Bullet points
        if (preg_match('/^[-*\x{2022}\x{2023}]\s/u', $line)) {
            return true;
        }

        // Numbered lists
        if (preg_match('/^\d+[.)]\s/', $line)) {
            return true;
        }

        // Lettered lists
        if (preg_match('/^[a-zA-Z][.)]\s/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Check if a new paragraph should start before this line.
     *
     * @param  array<string>  $previousLines
     */
    protected function shouldStartNewParagraph(array $previousLines, string $currentLine): bool
    {
        if (empty($previousLines)) {
            return false;
        }

        $lastLine = end($previousLines);
        if ($lastLine === '' || $lastLine === false) {
            return false;
        }

        $lastTrimmed = trim($lastLine);

        // If the previous line ends with sentence-ending punctuation
        // and current line starts with uppercase, likely a new paragraph
        if (preg_match('/[.!?][\'"»\)\]]*$/', $lastTrimmed)) {
            if (preg_match('/^[A-Z\d"]/', $currentLine)) {
                // Check if the previous line is very short (might be a heading or list)
                if (mb_strlen($lastTrimmed) > 40) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Update sections with normalized paragraph breaks.
     *
     * @param  array<Section>  $sections
     * @return array<Section>
     */
    protected function updateSections(array $sections, string $normalizedText): array
    {
        $updatedSections = [];

        foreach ($sections as $section) {
            $content = $section->content;

            // Apply same normalization to section content
            $content = preg_replace('/\n{3,}/', "\n\n", $content) ?? $content;
            $content = trim($content);

            $updatedSections[] = new Section(
                title: $section->title,
                level: $section->level,
                content: $content,
                startOffset: $section->startOffset,
                endOffset: $section->endOffset,
            );
        }

        return $updatedSections;
    }
}
