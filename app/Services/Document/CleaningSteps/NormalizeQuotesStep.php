<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class NormalizeQuotesStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'normalize_quotes';
    }

    public function getDescription(): string
    {
        return 'Converts smart quotes to straight quotes and normalizes dashes and ellipsis.';
    }

    public function getPriority(): int
    {
        return 70;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $changesMade = 0;
        $original = $text;

        // Smart quotes to straight quotes
        $quoteReplacements = [
            // Double quotes
            "\u{201C}" => '"',  // Left double quotation mark
            "\u{201D}" => '"',  // Right double quotation mark
            "\u{201E}" => '"',  // Double low-9 quotation mark
            "\u{201F}" => '"',  // Double high-reversed-9 quotation mark
            "\u{00AB}" => '"',  // Left-pointing double angle quotation mark
            "\u{00BB}" => '"',  // Right-pointing double angle quotation mark

            // Single quotes
            "\u{2018}" => "'",  // Left single quotation mark
            "\u{2019}" => "'",  // Right single quotation mark
            "\u{201A}" => "'",  // Single low-9 quotation mark
            "\u{201B}" => "'",  // Single high-reversed-9 quotation mark
            "\u{2039}" => "'",  // Single left-pointing angle quotation mark
            "\u{203A}" => "'",  // Single right-pointing angle quotation mark

            // Prime symbols (sometimes used as quotes)
            "\u{2032}" => "'",  // Prime
            "\u{2033}" => '"',  // Double prime
            "\u{2034}" => '"',  // Triple prime
        ];

        foreach ($quoteReplacements as $search => $replace) {
            if (str_contains($text, $search)) {
                $text = str_replace($search, $replace, $text);
                $changesMade++;
            }
        }

        // Normalize dashes
        $dashReplacements = [
            "\u{2014}" => '--',   // Em dash
            "\u{2013}" => '-',    // En dash
            "\u{2012}" => '-',    // Figure dash
            "\u{2015}" => '--',   // Horizontal bar
            "\u{2043}" => '-',    // Hyphen bullet
            "\u{2010}" => '-',    // Hyphen
            "\u{2011}" => '-',    // Non-breaking hyphen
        ];

        foreach ($dashReplacements as $search => $replace) {
            if (str_contains($text, $search)) {
                $text = str_replace($search, $replace, $text);
                $changesMade++;
            }
        }

        // Normalize ellipsis
        if (str_contains($text, "\u{2026}")) {
            $text = str_replace("\u{2026}", '...', $text);
            $changesMade++;
        }

        // Normalize other typographic characters
        $otherReplacements = [
            "\u{2022}" => '-',     // Bullet point to dash (for lists)
            "\u{00B7}" => '-',     // Middle dot
            "\u{2116}" => 'No.',   // Numero sign
            "\u{2122}" => '(TM)',  // Trademark
            "\u{00AE}" => '(R)',   // Registered trademark
            "\u{00A9}" => '(c)',   // Copyright
            "\u{2120}" => '(SM)',  // Service mark
            "\u{2103}" => 'C',     // Degree Celsius
            "\u{2109}" => 'F',     // Degree Fahrenheit
            "\u{00D7}" => 'x',     // Multiplication sign
            "\u{00F7}" => '/',     // Division sign
            "\u{00B1}" => '+/-',   // Plus-minus
            "\u{2248}" => '~',     // Approximately
            "\u{2260}" => '!=',    // Not equal
            "\u{2264}" => '<=',    // Less than or equal
            "\u{2265}" => '>=',    // Greater than or equal
        ];

        foreach ($otherReplacements as $search => $replace) {
            if (str_contains($text, $search)) {
                $text = str_replace($search, $replace, $text);
                $changesMade++;
            }
        }

        return [
            'text' => $text,
            'changes_made' => $changesMade,
        ];
    }
}
