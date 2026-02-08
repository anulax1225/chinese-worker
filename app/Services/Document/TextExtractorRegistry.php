<?php

namespace App\Services\Document;

use App\Contracts\TextExtractorInterface;
use App\DTOs\Document\ExtractionResult;
use RuntimeException;

class TextExtractorRegistry
{
    /**
     * Registered extractors.
     *
     * @var array<TextExtractorInterface>
     */
    protected array $extractors = [];

    /**
     * Register an extractor.
     */
    public function register(TextExtractorInterface $extractor): self
    {
        $this->extractors[] = $extractor;

        return $this;
    }

    /**
     * Get an extractor that supports the given MIME type.
     */
    public function getExtractor(string $mimeType): ?TextExtractorInterface
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mimeType)) {
                return $extractor;
            }
        }

        return null;
    }

    /**
     * Check if any extractor supports the given MIME type.
     */
    public function supports(string $mimeType): bool
    {
        return $this->getExtractor($mimeType) !== null;
    }

    /**
     * Get all supported MIME types.
     *
     * @return array<string>
     */
    public function getSupportedMimeTypes(): array
    {
        $mimeTypes = [];

        foreach ($this->extractors as $extractor) {
            $mimeTypes = array_merge($mimeTypes, $extractor->getSupportedMimeTypes());
        }

        return array_unique($mimeTypes);
    }

    /**
     * Extract text from a file using the appropriate extractor.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws RuntimeException When no extractor supports the MIME type
     */
    public function extract(string $filePath, string $mimeType, array $options = []): ExtractionResult
    {
        $extractor = $this->getExtractor($mimeType);

        if ($extractor === null) {
            return ExtractionResult::failure("No extractor available for MIME type: {$mimeType}");
        }

        return $extractor->extract($filePath, $options);
    }

    /**
     * Get all registered extractors.
     *
     * @return array<TextExtractorInterface>
     */
    public function getExtractors(): array
    {
        return $this->extractors;
    }

    /**
     * Get the count of registered extractors.
     */
    public function count(): int
    {
        return count($this->extractors);
    }
}
