<?php

namespace App\Services\Document\CleaningSteps;

use App\Contracts\CleaningStepInterface;

class RemoveBoilerplateStep implements CleaningStepInterface
{
    public function getName(): string
    {
        return 'remove_boilerplate';
    }

    public function getDescription(): string
    {
        return 'Removes common boilerplate text like copyright notices, disclaimers, and confidentiality statements.';
    }

    public function getPriority(): int
    {
        return 60;
    }

    /**
     * @return array{text: string, changes_made: int}
     */
    public function clean(string $text): array
    {
        $changesMade = 0;

        // Get patterns from config (if available)
        try {
            $configPatterns = config('document.cleaning.boilerplate_patterns', []);
        } catch (\Throwable) {
            $configPatterns = [];
        }

        // Default patterns if none in config
        $defaultPatterns = [
            '/^Copyright\s+(?:©|\(c\))?\s*\d{4}.*$/mi',
            '/^©\s*\d{4}.*$/m',
            '/^\(c\)\s*\d{4}.*$/mi',
            '/^All rights reserved\.?$/mi',
            '/^Page\s+\d+\s+of\s+\d+$/mi',
            '/^Confidential.*$/mi',
            '/^CONFIDENTIAL.*$/m',
            '/^DRAFT.*$/m',
            '/^For internal use only\.?$/mi',
            '/^Not for distribution\.?$/mi',
            '/^Proprietary and confidential\.?$/mi',
            '/^This document is confidential.*$/mi',
            '/^Printed on:?\s*\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}.*$/mi',
            '/^Last (?:modified|updated|saved):?\s*\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}.*$/mi',
            '/^Document ID:?\s*[\w\-]+$/mi',
            '/^Version:?\s*[\d\.]+$/mi',
            '/^Rev(?:ision)?\.?:?\s*[\d\.]+$/mi',
        ];

        $patterns = array_merge($defaultPatterns, $configPatterns);

        foreach ($patterns as $pattern) {
            // Validate pattern
            if (@preg_match($pattern, '') === false) {
                continue; // Skip invalid patterns
            }

            $newText = preg_replace($pattern, '', $text);
            if ($newText !== null && $newText !== $text) {
                $text = $newText;
                $changesMade++;
            }
        }

        // Remove common footer/header dividers if they appear alone
        $dividerPatterns = [
            '/^\s*[-=_]{3,}\s*$/m', // Lines of dashes, equals, or underscores
            '/^\s*\*{3,}\s*$/m',    // Lines of asterisks
        ];

        foreach ($dividerPatterns as $pattern) {
            $newText = preg_replace($pattern, '', $text);
            if ($newText !== null && $newText !== $text) {
                $text = $newText;
                $changesMade++;
            }
        }

        // Clean up excessive whitespace from removals
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return [
            'text' => trim($text),
            'changes_made' => $changesMade,
        ];
    }
}
