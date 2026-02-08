<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class RemoveControlCharactersStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'remove_control_characters';
    }

    public function getDescription(): string
    {
        return 'Removes non-printable control characters while preserving newlines and tabs.';
    }

    public function getPriority(): int
    {
        return 20;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $originalLength = mb_strlen($text);

        // Remove control characters except:
        // - \t (tab, 0x09)
        // - \n (newline, 0x0A)
        // - \r (carriage return, 0x0D) - will be normalized later
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);

        // Remove zero-width characters
        $zeroWidthChars = [
            "\u{200B}", // Zero-width space
            "\u{200C}", // Zero-width non-joiner
            "\u{200D}", // Zero-width joiner
            "\u{FEFF}", // Zero-width no-break space (BOM)
            "\u{00AD}", // Soft hyphen
        ];
        $text = str_replace($zeroWidthChars, '', $text);

        // Remove other invisible formatting characters
        $text = preg_replace('/[\x{2060}-\x{206F}]/u', '', $text); // General punctuation (invisible)
        $text = preg_replace('/[\x{FFF0}-\x{FFFF}]/u', '', $text); // Specials

        $changesMade = $originalLength - mb_strlen($text);

        return [
            'text' => $text,
            'changes_made' => $changesMade,
        ];
    }
}
