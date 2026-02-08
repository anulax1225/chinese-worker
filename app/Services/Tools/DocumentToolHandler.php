<?php

namespace App\Services\Tools;

use App\DTOs\ToolResult;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;

class DocumentToolHandler
{
    public function __construct(
        protected Conversation $conversation
    ) {}

    /**
     * Execute a document tool by name.
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(string $toolName, array $args): ToolResult
    {
        return match ($toolName) {
            'document_list' => $this->list(),
            'document_info' => $this->info($args),
            'document_get_chunks' => $this->getChunks($args),
            'document_search' => $this->search($args),
            default => new ToolResult(
                success: false,
                output: '',
                error: "Unknown document tool: {$toolName}"
            ),
        };
    }

    /**
     * List all documents attached to this conversation.
     */
    protected function list(): ToolResult
    {
        $documents = $this->conversation->documents()
            ->withCount('chunks')
            ->withSum('chunks', 'token_count')
            ->get();

        if ($documents->isEmpty()) {
            return new ToolResult(
                success: true,
                output: 'No documents attached to this conversation.',
                error: null
            );
        }

        $output = "Attached Documents:\n";
        foreach ($documents as $document) {
            $output .= sprintf(
                "- [%d] %s (Status: %s, Chunks: %d, Tokens: %d)\n",
                $document->id,
                $document->title ?? 'Untitled',
                $document->status->value,
                $document->chunks_count ?? 0,
                (int) ($document->chunks_sum_token_count ?? 0)
            );
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Get detailed info about a specific document.
     *
     * @param  array<string, mixed>  $args
     */
    protected function info(array $args): ToolResult
    {
        $documentId = $args['document_id'] ?? null;
        if (! $documentId) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'document_id is required'
            );
        }

        $document = $this->findDocument($documentId);
        if (! $document) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Document not found or not attached: {$documentId}"
            );
        }

        $chunkCount = $document->getChunkCount();
        $totalTokens = $document->getTotalTokens();

        $output = sprintf(
            "Document: %s\n".
            "ID: %d\n".
            "Status: %s\n".
            "Source: %s (%s)\n".
            "MIME Type: %s\n".
            "File Size: %s bytes\n".
            "Total Chunks: %d\n".
            "Total Tokens: %d\n".
            "Word Count: %s\n",
            $document->title ?? 'Untitled',
            $document->id,
            $document->status->label(),
            $document->source_type->value,
            $document->source_path ?? 'N/A',
            $document->mime_type,
            number_format($document->file_size ?? 0),
            $chunkCount,
            $totalTokens,
            $document->getWordCount() ? number_format($document->getWordCount()) : 'Unknown'
        );

        // List section titles if available
        $sections = $document->chunks()
            ->whereNotNull('section_title')
            ->distinct()
            ->pluck('section_title');

        if ($sections->isNotEmpty()) {
            $output .= "\nSections:\n";
            foreach ($sections as $section) {
                $output .= "- {$section}\n";
            }
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Get specific chunks from a document.
     *
     * @param  array<string, mixed>  $args
     */
    protected function getChunks(array $args): ToolResult
    {
        $documentId = $args['document_id'] ?? null;
        if (! $documentId) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'document_id is required'
            );
        }

        $document = $this->findDocument($documentId);
        if (! $document) {
            return new ToolResult(
                success: false,
                output: '',
                error: "Document not found or not attached: {$documentId}"
            );
        }

        $startIndex = $args['start_index'] ?? 0;
        $endIndex = $args['end_index'] ?? $startIndex;

        // Limit range to prevent excessive token usage
        $maxChunks = 10;
        if (($endIndex - $startIndex + 1) > $maxChunks) {
            $endIndex = $startIndex + $maxChunks - 1;
        }

        $chunks = $document->chunks()
            ->inRange($startIndex, $endIndex)
            ->ordered()
            ->get();

        if ($chunks->isEmpty()) {
            return new ToolResult(
                success: false,
                output: '',
                error: "No chunks found in range {$startIndex}-{$endIndex}"
            );
        }

        $totalChunks = $document->getChunkCount();
        $docTitle = $document->title ?? 'Untitled';
        $output = "Document: {$docTitle} (Chunks {$startIndex}-{$endIndex} of ".($totalChunks - 1).")\n";
        $output .= str_repeat('-', 60)."\n";

        foreach ($chunks as $chunk) {
            if ($chunk->section_title) {
                $output .= "[Section: {$chunk->section_title}]\n";
            }
            $output .= "[Chunk {$chunk->chunk_index}] ({$chunk->token_count} tokens)\n";
            $output .= $chunk->content."\n\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Search within document content.
     *
     * @param  array<string, mixed>  $args
     */
    protected function search(array $args): ToolResult
    {
        $query = $args['query'] ?? null;
        if (! $query || strlen($query) < 2) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Search query must be at least 2 characters'
            );
        }

        $documentId = $args['document_id'] ?? null;
        $maxResults = min($args['max_results'] ?? 5, 10);

        // Get document IDs attached to this conversation
        $documentIds = $this->conversation->getDocumentIds();

        if (empty($documentIds)) {
            return new ToolResult(
                success: true,
                output: 'No documents attached to search.',
                error: null
            );
        }

        // Build query - search in specific document or all attached documents
        $chunksQuery = DocumentChunk::query()
            ->whereIn('document_id', $documentIds)
            ->search($query)
            ->ordered()
            ->limit($maxResults);

        if ($documentId) {
            $document = $this->findDocument($documentId);
            if (! $document) {
                return new ToolResult(
                    success: false,
                    output: '',
                    error: "Document not found or not attached: {$documentId}"
                );
            }
            $chunksQuery->forDocument($document);
        }

        $chunks = $chunksQuery->with('document')->get();

        if ($chunks->isEmpty()) {
            return new ToolResult(
                success: true,
                output: "No results found for: {$query}",
                error: null
            );
        }

        $output = "Search results for \"{$query}\":\n";
        $output .= str_repeat('-', 60)."\n";

        foreach ($chunks as $chunk) {
            $docTitle = $chunk->document->title ?? 'Untitled';
            $output .= "[Doc {$chunk->document_id}: {$docTitle}] Chunk {$chunk->chunk_index}\n";
            $output .= $chunk->getPreview(300)."\n\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Find a document that is attached to this conversation.
     */
    protected function findDocument(int $documentId): ?Document
    {
        return $this->conversation->documents()
            ->where('documents.id', $documentId)
            ->first();
    }
}
