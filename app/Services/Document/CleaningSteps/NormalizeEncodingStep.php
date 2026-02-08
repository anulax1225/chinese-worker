<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class NormalizeEncodingStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'normalize_encoding';
    }

    public function getDescription(): string
    {
        return 'Converts text to UTF-8, removes BOM, and fixes common encoding issues.';
    }

    public function getPriority(): int
    {
        return 10;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $original = $text;
        $changesMade = 0;

        // Remove UTF-8 BOM
        if (str_starts_with($text, "\xEF\xBB\xBF")) {
            $text = substr($text, 3);
            $changesMade++;
        }

        // Remove UTF-16 BE BOM
        if (str_starts_with($text, "\xFE\xFF")) {
            $text = substr($text, 2);
            $changesMade++;
        }

        // Remove UTF-16 LE BOM
        if (str_starts_with($text, "\xFF\xFE")) {
            $text = substr($text, 2);
            $changesMade++;
        }

        // Detect and convert encoding to UTF-8
        $encoding = mb_detect_encoding($text, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);

        if ($encoding && $encoding !== 'UTF-8') {
            $converted = mb_convert_encoding($text, 'UTF-8', $encoding);
            if ($converted !== false) {
                $text = $converted;
                $changesMade++;
            }
        }

        // Fix common mojibake patterns (UTF-8 interpreted as Windows-1252)
        $mojibakePatterns = [
            'â€™' => "'",  // Right single quotation mark
            'â€œ' => '"',  // Left double quotation mark
            'â€' => '"',   // Right double quotation mark
            'â€"' => '—',  // Em dash
            'â€"' => '–',  // En dash
            'â€¦' => '…',  // Ellipsis
            'Ã©' => 'é',
            'Ã¨' => 'è',
            'Ã ' => 'à',
            'Ã¢' => 'â',
            'Ã´' => 'ô',
            'Ã®' => 'î',
            'Ã»' => 'û',
            'Ã§' => 'ç',
            'Ã«' => 'ë',
            'Ã¯' => 'ï',
            'Ã¼' => 'ü',
        ];

        foreach ($mojibakePatterns as $pattern => $replacement) {
            if (str_contains($text, $pattern)) {
                $text = str_replace($pattern, $replacement, $text);
                $changesMade++;
            }
        }

        // Ensure valid UTF-8 by removing invalid sequences
        $cleanedText = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        if ($cleanedText !== $text) {
            $text = $cleanedText;
            $changesMade++;
        }

        return [
            'text' => $text,
            'changes_made' => $changesMade,
        ];
    }
}
