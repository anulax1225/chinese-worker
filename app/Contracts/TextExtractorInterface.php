<?php

namespace App\Contracts;

use App\DTOs\Document\ExtractionResult;

interface TextExtractorInterface
{
    /**
     * Check if this extractor supports the given MIME type.
     */
    public function supports(string $mimeType): bool;

    /**
     * Extract text content from a file.
     *
     * @param  array<string, mixed>  $options
     */
    public function extract(string $filePath, array $options = []): ExtractionResult;

    /**
     * Get the name of this extractor.
     */
    public function getName(): string;

    /**
     * Get the list of MIME types this extractor supports.
     *
     * @return array<string>
     */
    public function getSupportedMimeTypes(): array;
}
