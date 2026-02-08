<?php

namespace App\Services\Document\Extractors;

use App\Contracts\TextExtractorInterface;
use App\DTOs\Document\ExtractionResult;
use Illuminate\Support\Facades\File;

class PlainTextExtractor implements TextExtractorInterface
{
    /**
     * MIME types this extractor supports.
     *
     * @var array<string>
     */
    protected array $supportedMimeTypes = [
        'text/plain',
        'text/markdown',
        'text/x-markdown',
        'text/csv',
        'text/tab-separated-values',
    ];

    /**
     * Check if this extractor supports the given MIME type.
     */
    public function supports(string $mimeType): bool
    {
        // Support exact matches
        if (in_array($mimeType, $this->supportedMimeTypes, true)) {
            return true;
        }

        // Support generic text/* types
        if (str_starts_with($mimeType, 'text/')) {
            return true;
        }

        return false;
    }

    /**
     * Extract text content from a file.
     *
     * @param  array<string, mixed>  $options
     */
    public function extract(string $filePath, array $options = []): ExtractionResult
    {
        if (! File::exists($filePath)) {
            return ExtractionResult::failure("File not found: {$filePath}");
        }

        $content = File::get($filePath);
        $warnings = [];

        // Detect and convert encoding if needed
        $encoding = $this->detectEncoding($content);
        if ($encoding !== 'UTF-8' && $encoding !== false) {
            $converted = @mb_convert_encoding($content, 'UTF-8', $encoding);
            if ($converted !== false) {
                $content = $converted;
                $warnings[] = "Converted from {$encoding} to UTF-8";
            }
        }

        // Remove BOM if present
        $content = $this->removeBom($content);

        // Build metadata
        $metadata = [
            'original_encoding' => $encoding ?: 'unknown',
            'line_count' => substr_count($content, "\n") + 1,
            'file_extension' => pathinfo($filePath, PATHINFO_EXTENSION),
        ];

        return ExtractionResult::success($content, $metadata, $warnings);
    }

    /**
     * Get the name of this extractor.
     */
    public function getName(): string
    {
        return 'plain_text';
    }

    /**
     * Get the list of MIME types this extractor supports.
     *
     * @return array<string>
     */
    public function getSupportedMimeTypes(): array
    {
        return $this->supportedMimeTypes;
    }

    /**
     * Detect the encoding of a string.
     */
    protected function detectEncoding(string $content): string|false
    {
        return mb_detect_encoding($content, [
            'UTF-8',
            'ASCII',
            'ISO-8859-1',
            'ISO-8859-15',
            'Windows-1252',
            'UTF-16',
            'UTF-16LE',
            'UTF-16BE',
        ], true);
    }

    /**
     * Remove BOM (Byte Order Mark) from the beginning of content.
     */
    protected function removeBom(string $content): string
    {
        // UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        // UTF-16 BE BOM
        if (str_starts_with($content, "\xFE\xFF")) {
            return substr($content, 2);
        }

        // UTF-16 LE BOM
        if (str_starts_with($content, "\xFF\xFE")) {
            return substr($content, 2);
        }

        return $content;
    }
}
