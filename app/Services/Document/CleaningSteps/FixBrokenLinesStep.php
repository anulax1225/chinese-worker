<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class FixBrokenLinesStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'fix_broken_lines';
    }

    public function getDescription(): string
    {
        return 'Rejoins lines that were broken mid-word (common in PDFs) while preserving paragraph breaks.';
    }

    public function getPriority(): int
    {
        return 40;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $changesMade = 0;

        // Split into paragraphs (double newline)
        $paragraphs = preg_split('/\n\n+/', $text);
        $processedParagraphs = [];

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) {
                continue;
            }

            // Process single paragraph - join broken lines
            $original = $paragraph;
            $paragraph = $this->joinBrokenLines($paragraph);

            if ($paragraph !== $original) {
                $changesMade++;
            }

            $processedParagraphs[] = $paragraph;
        }

        $result = implode("\n\n", $processedParagraphs);

        return [
            'text' => $result,
            'changes_made' => $changesMade,
        ];
    }

    /**
     * Join lines that appear to be broken mid-sentence.
     */
    protected function joinBrokenLines(string $paragraph): string
    {
        $lines = explode("\n", $paragraph);
        $result = [];
        $buffer = '';

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                if ($buffer !== '') {
                    $result[] = $buffer;
                    $buffer = '';
                }

                continue;
            }

            // Check if this is a list item or heading (shouldn't be joined)
            if ($this->isListItem($line) || $this->isHeading($line)) {
                if ($buffer !== '') {
                    $result[] = $buffer;
                    $buffer = '';
                }
                $result[] = $line;

                continue;
            }

            if ($buffer === '') {
                $buffer = $line;
            } else {
                // Check if the previous line ended mid-sentence
                if ($this->shouldJoinWithPrevious($buffer, $line)) {
                    // Check if it's a hyphenated word break
                    if (str_ends_with($buffer, '-')) {
                        // Remove the hyphen and join directly
                        $buffer = substr($buffer, 0, -1).$line;
                    } else {
                        // Join with a space
                        $buffer .= ' '.$line;
                    }
                } else {
                    // Start a new line
                    $result[] = $buffer;
                    $buffer = $line;
                }
            }
        }

        if ($buffer !== '') {
            $result[] = $buffer;
        }

        return implode("\n", $result);
    }

    /**
     * Determine if a line should be joined with the previous line.
     */
    protected function shouldJoinWithPrevious(string $previous, string $current): bool
    {
        // Don't join if previous line ends with sentence-ending punctuation
        if (preg_match('/[.!?:;][\'"»\)\]]*$/', $previous)) {
            return false;
        }

        // Don't join if previous line ends with a colon (often introduces a list)
        if (str_ends_with($previous, ':')) {
            return false;
        }

        // Don't join if current line starts with a capital letter after a complete thought
        // But DO join if previous line ends with a lowercase letter or comma
        $previousEndsLower = preg_match('/[a-z,\-]$/', $previous);
        $currentStartsUpper = preg_match('/^[A-Z]/', $current);

        // If previous ends with lowercase and current starts with lowercase, likely broken
        if ($previousEndsLower && ! $currentStartsUpper) {
            return true;
        }

        // If previous ends with hyphen, it's definitely a broken word
        if (str_ends_with($previous, '-')) {
            return true;
        }

        // If previous ends with comma, likely continues
        if (str_ends_with($previous, ',')) {
            return true;
        }

        // If current starts with lowercase, likely continuation
        if (preg_match('/^[a-z]/', $current)) {
            return true;
        }

        return false;
    }

    /**
     * Check if line is a list item.
     */
    protected function isListItem(string $line): bool
    {
        // Bullet points
        if (preg_match('/^[\-\*\•\◦\▪\▸]\s/', $line)) {
            return true;
        }

        // Numbered lists
        if (preg_match('/^\d+[\.\)]\s/', $line)) {
            return true;
        }

        // Lettered lists
        if (preg_match('/^[a-zA-Z][\.\)]\s/', $line)) {
            return true;
        }

        return false;
    }

    /**
     * Check if line is likely a heading.
     */
    protected function isHeading(string $line): bool
    {
        // All caps and short
        if (mb_strlen($line) < 80 && $line === mb_strtoupper($line) && preg_match('/[A-Z]/', $line)) {
            return true;
        }

        // Markdown-style heading
        if (preg_match('/^#{1,6}\s/', $line)) {
            return true;
        }

        // Numbered heading
        if (preg_match('/^\d+(\.\d+)*\s+[A-Z]/', $line) && mb_strlen($line) < 100) {
            return true;
        }

        return false;
    }
}
