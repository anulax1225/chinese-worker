<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class NormalizeWhitespaceStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'normalize_whitespace';
    }

    public function getDescription(): string
    {
        return 'Normalizes line endings, collapses multiple spaces, and removes trailing whitespace.';
    }

    public function getPriority(): int
    {
        return 30;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $changesMade = 0;
        $original = $text;

        // Normalize line endings to Unix-style (\n)
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if ($text !== $original) {
            $changesMade++;
            $original = $text;
        }

        // Replace various Unicode space characters with regular space
        $unicodeSpaces = [
            "\u{00A0}", // Non-breaking space
            "\u{2000}", // En quad
            "\u{2001}", // Em quad
            "\u{2002}", // En space
            "\u{2003}", // Em space
            "\u{2004}", // Three-per-em space
            "\u{2005}", // Four-per-em space
            "\u{2006}", // Six-per-em space
            "\u{2007}", // Figure space
            "\u{2008}", // Punctuation space
            "\u{2009}", // Thin space
            "\u{200A}", // Hair space
            "\u{202F}", // Narrow no-break space
            "\u{205F}", // Medium mathematical space
            "\u{3000}", // Ideographic space
        ];
        $text = str_replace($unicodeSpaces, ' ', $text);
        if ($text !== $original) {
            $changesMade++;
            $original = $text;
        }

        // Collapse multiple spaces into single space
        $text = preg_replace('/[ \t]+/', ' ', $text);
        if ($text !== $original) {
            $changesMade++;
            $original = $text;
        }

        // Remove trailing whitespace from each line
        $text = preg_replace('/[ \t]+$/m', '', $text);
        if ($text !== $original) {
            $changesMade++;
            $original = $text;
        }

        // Remove leading whitespace from each line (except intentional indentation)
        // Only remove if it's just spaces at the very start of lines
        $text = preg_replace('/^[ \t]+(?=\S)/m', '', $text);
        if ($text !== $original) {
            $changesMade++;
            $original = $text;
        }

        // Collapse excessive blank lines (more than 2 newlines become 2)
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        if ($text !== $original) {
            $changesMade++;
        }

        // Trim the entire text
        $text = trim($text);

        return [
            'text' => $text,
            'changes_made' => $changesMade,
        ];
    }
}
