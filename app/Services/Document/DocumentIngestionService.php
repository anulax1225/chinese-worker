<?php

namespace App\Services\Document;

use App\Enums\DocumentSourceType;
use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocumentJob;
use App\Models\Document;
use App\Models\File;
use App\Models\User;
use App\Services\FileService;
use Illuminate\Support\Facades\Storage;

class DocumentIngestionService
{
    public function __construct(
        protected TextExtractorRegistry $extractorRegistry,
        protected FileService $fileService,
    ) {}

    /**
     * Ingest a document from an uploaded file.
     */
    public function ingestFromFile(File $file, User $user, ?string $title = null): Document
    {
        $document = Document::create([
            'user_id' => $user->id,
            'file_id' => $file->id,
            'title' => $title ?? $this->generateTitleFromPath($file->path),
            'source_type' => DocumentSourceType::Upload,
            'source_path' => $file->path,
            'mime_type' => $file->mime_type,
            'file_size' => $file->size,
            'status' => DocumentStatus::Pending,
            'metadata' => [
                'original_filename' => basename($file->path),
            ],
        ]);

        $this->dispatchProcessingJob($document);

        return $document;
    }

    /**
     * Ingest a document from a URL.
     */
    public function ingestFromUrl(string $url, User $user, ?string $title = null): Document
    {
        $tempPath = $this->downloadFromUrl($url);
        $mimeType = $this->detectMimeType($tempPath);

        $file = $this->fileService->storeFromPath($tempPath, 'input', $user->id, $mimeType);
        @unlink($tempPath);

        $document = Document::create([
            'user_id' => $user->id,
            'file_id' => $file->id,
            'title' => $title ?? $this->generateTitleFromUrl($url),
            'source_type' => DocumentSourceType::Url,
            'source_path' => $url,
            'mime_type' => $mimeType,
            'file_size' => $file->size,
            'status' => DocumentStatus::Pending,
            'metadata' => [
                'original_url' => $url,
            ],
        ]);

        $this->dispatchProcessingJob($document);

        return $document;
    }

    /**
     * Ingest a document from pasted text.
     */
    public function ingestFromText(string $text, User $user, ?string $title = null): Document
    {
        // Store the text to a temp file
        $tempPath = $this->storeTextToTemp($text);

        $document = Document::create([
            'user_id' => $user->id,
            'title' => $title ?? 'Pasted Text',
            'source_type' => DocumentSourceType::Paste,
            'source_path' => $tempPath,
            'mime_type' => 'text/plain',
            'file_size' => strlen($text),
            'status' => DocumentStatus::Pending,
            'metadata' => [
                'pasted_at' => now()->toIso8601String(),
            ],
        ]);

        $this->dispatchProcessingJob($document);

        return $document;
    }

    /**
     * Reprocess an existing document.
     */
    public function reprocess(Document $document): void
    {
        // Clear existing stages and chunks
        $document->stages()->delete();
        $document->chunks()->delete();

        // Reset status
        $document->update([
            'status' => DocumentStatus::Pending,
            'error_message' => null,
            'processing_started_at' => null,
            'processing_completed_at' => null,
        ]);

        $this->dispatchProcessingJob($document);
    }

    /**
     * Delete a document and its associated data.
     */
    public function delete(Document $document): void
    {
        // Delete stages and chunks (cascade should handle this, but be explicit)
        $document->stages()->delete();
        $document->chunks()->delete();

        // Delete temp files for legacy documents without a file record
        if (isset($document->metadata['temp_path'])) {
            @unlink($document->metadata['temp_path']);
        }

        // Delete associated File record (covers uploads and url documents)
        if ($document->file_id && $document->file) {
            $this->fileService->delete($document->file);
        }

        $document->delete();
    }

    /**
     * Check if a MIME type is supported.
     */
    public function isSupported(string $mimeType): bool
    {
        return $this->extractorRegistry->supports($mimeType);
    }

    /**
     * Get all supported MIME types.
     *
     * @return array<string>
     */
    public function getSupportedMimeTypes(): array
    {
        return $this->extractorRegistry->getSupportedMimeTypes();
    }

    /**
     * Dispatch the processing job for a document.
     */
    protected function dispatchProcessingJob(Document $document): void
    {
        ProcessDocumentJob::dispatch($document);
    }

    /**
     * Generate a title from a file path.
     */
    protected function generateTitleFromPath(string $path): string
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);

        // Convert underscores and dashes to spaces
        $title = str_replace(['_', '-'], ' ', $filename);

        // Title case
        return ucwords($title);
    }

    /**
     * Generate a title from a URL.
     */
    protected function generateTitleFromUrl(string $url): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $filename = pathinfo($path, PATHINFO_FILENAME);

        if (empty($filename)) {
            return $parsed['host'] ?? 'Web Document';
        }

        return $this->generateTitleFromPath($filename);
    }

    /**
     * Download a file from URL to a temporary location.
     */
    protected function downloadFromUrl(string $url): string
    {
        $tempPath = sys_get_temp_dir().'/'.uniqid('doc_', true);

        $context = stream_context_create([
            'http' => [
                'timeout' => config('document.extraction.timeout', 60),
                'user_agent' => 'ChineseWorker/1.0 Document Fetcher',
            ],
        ]);

        $content = file_get_contents($url, false, $context);

        if ($content === false) {
            throw new \RuntimeException("Failed to download file from URL: {$url}");
        }

        file_put_contents($tempPath, $content);

        return $tempPath;
    }

    /**
     * Store text content to a temporary file.
     */
    protected function storeTextToTemp(string $text): string
    {
        $tempPath = sys_get_temp_dir().'/'.uniqid('doc_text_', true).'.txt';
        file_put_contents($tempPath, $text);

        return $tempPath;
    }

    /**
     * Detect MIME type of a file.
     */
    protected function detectMimeType(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($path);

        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Get the absolute path for a document's source file.
     */
    public function getSourcePath(Document $document): string
    {
        if ($document->source_type === DocumentSourceType::Paste) {
            return $document->source_path;
        }

        if ($document->source_type === DocumentSourceType::Url) {
            if ($document->file_id) {
                $disk = config('document.storage.disk', 'local');

                return Storage::disk($disk)->path($document->file->path);
            }

            // Fallback for legacy documents without a file record
            return $document->metadata['temp_path'] ?? $document->source_path;
        }

        // For uploads, get the full path from storage
        $disk = config('document.storage.disk', 'local');

        return Storage::disk($disk)->path($document->source_path);
    }
}
