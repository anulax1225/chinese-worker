<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class RemoveHeadersFootersStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'remove_headers_footers';
    }

    public function getDescription(): string
    {
        return 'Detects and removes repeating page headers, footers, and page numbers.';
    }

    public function getPriority(): int
    {
        return 50;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $changesMade = 0;
        $original = $text;

        // Remove common page number patterns
        $pagePatterns = [
            '/^Page\s+\d+\s*(?:of\s+\d+)?$/mi',
            '/^\d+\s*(?:of\s+\d+)?$/m',
            '/^-\s*\d+\s*-$/m',
            '/^\[\s*\d+\s*\]$/m',
            '/^p\.\s*\d+$/mi',
        ];

        foreach ($pagePatterns as $pattern) {
            $newText = preg_replace($pattern, '', $text);
            if ($newText !== $text) {
                $text = $newText;
                $changesMade++;
            }
        }

        // Detect and remove repeated short lines that appear frequently
        // (likely headers or footers)
        $text = $this->removeRepeatedPatterns($text, $changesMade);

        // Clean up any resulting excessive whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        if ($text !== $original) {
            $changesMade++;
        }

        return [
            'text' => trim($text),
            'changes_made' => $changesMade,
        ];
    }

    /**
     * Remove lines that appear more than a threshold number of times.
     * These are likely headers or footers.
     */
    protected function removeRepeatedPatterns(string $text, int &$changesMade): string
    {
        $lines = explode("\n", $text);
        $lineCounts = [];

        // Count occurrences of each short line
        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Only consider short lines (headers/footers are usually short)
            if (mb_strlen($trimmed) > 0 && mb_strlen($trimmed) < 80) {
                $normalized = mb_strtolower($trimmed);
                $lineCounts[$normalized] = ($lineCounts[$normalized] ?? 0) + 1;
            }
        }

        // Identify patterns that appear too often (threshold: 3+ times)
        $threshold = 3;
        $repeatedPatterns = [];
        foreach ($lineCounts as $pattern => $count) {
            if ($count >= $threshold) {
                // Skip legitimate repeated content (like "Introduction", single words)
                if (mb_strlen($pattern) < 3 || preg_match('/^[a-z]+$/i', $pattern)) {
                    continue;
                }
                $repeatedPatterns[] = $pattern;
            }
        }

        // Remove the repeated patterns
        if (! empty($repeatedPatterns)) {
            $filteredLines = [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                $normalized = mb_strtolower($trimmed);

                if (in_array($normalized, $repeatedPatterns, true)) {
                    $changesMade++;

                    continue; // Skip this line
                }

                $filteredLines[] = $line;
            }
            $text = implode("\n", $filteredLines);
        }

        return $text;
    }
}
