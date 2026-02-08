<?php

namespace App\Services\Document\StructureProcessors;

use App\Contracts\StructureProcessorInterface;
use App\DTOs\Document\Section;
use App\DTOs\Document\StructuredContent;

class ListNormalizer implements StructureProcessorInterface
{
    public function getName(): string
    {
        return 'list_normalizer';
    }

    public function getDescription(): string
    {
        return 'Normalizes bulleted and numbered lists to a consistent format.';
    }

    public function getPriority(): int
    {
        return 20;
    }

    public function process(StructuredContent $content): StructuredContent
    {
        $text = $content->text;
        $listsNormalized = 0;

        // Normalize bullet points
        $bulletPatterns = [
            '/^[\x{2022}\x{2023}\x{25E6}\x{25AA}\x{25AB}\x{25CF}\x{25CB}\x{2219}\x{2043}]\s*/mu', // Unicode bullets
            '/^[*\x{2217}]\s+/mu', // Asterisks
            '/^>\s+(?![>])/m', // Single > (not >>)
        ];

        foreach ($bulletPatterns as $pattern) {
            $newText = preg_replace($pattern, '- ', $text);
            if ($newText !== null && $newText !== $text) {
                $text = $newText;
                $listsNormalized++;
            }
        }

        // Normalize numbered lists (various formats to "1. ")
        // Match: 1) item, (1) item, 1- item
        $numberedPatterns = [
            '/^(\d+)\)\s+/m' => '$1. ',      // 1) -> 1.
            '/^\((\d+)\)\s+/m' => '$1. ',    // (1) -> 1.
            '/^(\d+)-\s+/m' => '$1. ',       // 1- -> 1.
            '/^(\d+):\s+/m' => '$1. ',       // 1: -> 1.
        ];

        foreach ($numberedPatterns as $pattern => $replacement) {
            $newText = preg_replace($pattern, $replacement, $text);
            if ($newText !== null && $newText !== $text) {
                $text = $newText;
                $listsNormalized++;
            }
        }

        // Normalize lettered lists
        $letteredPatterns = [
            '/^([a-zA-Z])\)\s+/m' => '$1. ',   // a) -> a.
            '/^\(([a-zA-Z])\)\s+/m' => '$1. ', // (a) -> a.
        ];

        foreach ($letteredPatterns as $pattern => $replacement) {
            $newText = preg_replace($pattern, $replacement, $text);
            if ($newText !== null && $newText !== $text) {
                $text = $newText;
                $listsNormalized++;
            }
        }

        // Update sections with normalized text
        $sections = $this->updateSectionContent($content->sections, $content->text, $text);

        $metadata = $content->metadata;
        $metadata['lists_normalized'] = $listsNormalized;

        return new StructuredContent(
            text: $text,
            sections: $sections,
            metadata: $metadata,
        );
    }

    /**
     * Update section content after text modifications.
     *
     * @param  array<Section>  $sections
     * @return array<Section>
     */
    protected function updateSectionContent(array $sections, string $originalText, string $newText): array
    {
        if ($originalText === $newText) {
            return $sections;
        }

        // If text changed significantly, we need to re-extract sections
        // For now, just update the overall text in sections
        $updatedSections = [];
        foreach ($sections as $section) {
            // Simple approach: try to find the section content in the new text
            $originalContent = $section->content;
            $newContent = $this->normalizeListsInText($originalContent);

            $updatedSections[] = new Section(
                title: $section->title,
                level: $section->level,
                content: $newContent,
                startOffset: $section->startOffset,
                endOffset: $section->endOffset,
            );
        }

        return $updatedSections;
    }

    /**
     * Normalize lists in a piece of text.
     */
    protected function normalizeListsInText(string $text): string
    {
        // Apply the same normalizations to section content
        $bulletPatterns = [
            '/^[\x{2022}\x{2023}\x{25E6}\x{25AA}\x{25AB}\x{25CF}\x{25CB}\x{2219}\x{2043}]\s*/mu',
            '/^[*\x{2217}]\s+/mu',
        ];

        foreach ($bulletPatterns as $pattern) {
            $text = preg_replace($pattern, '- ', $text) ?? $text;
        }

        $numberedPatterns = [
            '/^(\d+)\)\s+/m' => '$1. ',
            '/^\((\d+)\)\s+/m' => '$1. ',
            '/^(\d+)-\s+/m' => '$1. ',
        ];

        foreach ($numberedPatterns as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? $text;
        }

        return $text;
    }
}
