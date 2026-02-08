<?php

namespace App\Services\Document\Extractors;

use App\Contracts\TextExtractorInterface;
use App\DTOs\Document\ExtractionResult;
use Nilgems\PhpTextract\Textract;

class TextractExtractor implements TextExtractorInterface
{
    /**
     * @var array<string>
     */
    protected array $supportedMimeTypes = [
        // Documents
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/rtf',
        'application/vnd.oasis.opendocument.text',
        // Spreadsheets
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        // Presentations
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        // Images (OCR)
        'image/jpeg',
        'image/png',
        'image/gif',
        // HTML
        'text/html',
        'application/xhtml+xml',
    ];

    public function getName(): string
    {
        return 'textract';
    }

    public function getSupportedMimeTypes(): array
    {
        return $this->supportedMimeTypes;
    }

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, $this->supportedMimeTypes, true);
    }

    public function extract(string $filePath, array $options = []): ExtractionResult
    {
        if (! file_exists($filePath)) {
            return ExtractionResult::failure("File not found: {$filePath}");
        }

        try {
            $output = Textract::run($filePath);

            $text = $output->text ?? '';

            // Decode HTML entities that Textract applies
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

            if (empty(trim($text))) {
                return ExtractionResult::failure('No text content could be extracted from the file');
            }

            return ExtractionResult::success(
                text: $text,
                metadata: [
                    'word_count' => $output->word_count ?? 0,
                    'extractor' => 'textract',
                    'source_format' => pathinfo($filePath, PATHINFO_EXTENSION),
                ],
            );
        } catch (\Throwable $e) {
            return ExtractionResult::failure(
                "Textract extraction failed: {$e->getMessage()}"
            );
        }
    }
}
