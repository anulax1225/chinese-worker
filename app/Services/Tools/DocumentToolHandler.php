<?php

namespace App\Services\Tools;

use App\DTOs\ToolResult;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\RAG\RAGPipeline;

class DocumentToolHandler
{
    public function __construct(
        protected Conversation $conversation,
        protected RAGPipeline $ragPipeline,
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
            'document_read_file' => $this->readFile($args),
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
     * Read the entire content of a document at once.
     *
     * @param  array<string, mixed>  $args
     */
    protected function readFile(array $args): ToolResult
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

        $totalTokens = $document->getTotalTokens();
        $maxTokens = 50000;

        if ($totalTokens > $maxTokens) {
            $chunkCount = $document->getChunkCount();

            return new ToolResult(
                success: false,
                output: '',
                error: "Document is too large to read at once ({$totalTokens} tokens, limit: {$maxTokens}). Use document_get_chunks with start_index/end_index to read in parts. Total chunks: {$chunkCount}"
            );
        }

        $chunks = $document->chunks()
            ->ordered()
            ->get();

        if ($chunks->isEmpty()) {
            return new ToolResult(
                success: true,
                output: 'Document has no content.',
                error: null
            );
        }

        $docTitle = $document->title ?? 'Untitled';
        $output = "Document: {$docTitle} ({$chunks->count()} chunks, {$totalTokens} tokens)\n";
        $output .= str_repeat('=', 60)."\n\n";

        foreach ($chunks as $chunk) {
            if ($chunk->section_title) {
                $output .= "## {$chunk->section_title}\n\n";
            }
            $output .= $chunk->content."\n\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Search within document content using RAG pipeline (with keyword fallback).
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

        // Resolve documents to search
        if ($documentId) {
            $document = $this->findDocument($documentId);
            if (! $document) {
                return new ToolResult(
                    success: false,
                    output: '',
                    error: "Document not found or not attached: {$documentId}"
                );
            }
            $documents = collect([$document]);
        } else {
            $documents = $this->conversation->documents;
        }

        if ($documents->isEmpty()) {
            return new ToolResult(
                success: true,
                output: 'No documents attached to search.',
                error: null
            );
        }

        // Try RAG pipeline first
        $ragResult = $this->ragPipeline->execute($query, $documents, [
            'top_k' => $maxResults,
            'conversation_id' => $this->conversation->id,
            'user_id' => $this->conversation->user_id,
        ]);

        if ($ragResult->success && $ragResult->hasContext()) {
            return $this->formatRagResults($query, $ragResult);
        }

        // Fallback to keyword search when RAG is disabled or no embeddings
        return $this->keywordSearch($query, $documents, $maxResults);
    }

    /**
     * Format RAG pipeline results into a tool result.
     */
    protected function formatRagResults(string $query, \App\Services\RAG\RAGPipelineResult $ragResult): ToolResult
    {
        $output = "Search results for \"{$query}\" (strategy: {$ragResult->strategy()}):\n";
        $output .= str_repeat('-', 60)."\n";

        foreach ($ragResult->retrieval->chunks as $chunk) {
            $docTitle = $chunk->document->title ?? 'Untitled';
            $score = $ragResult->retrieval->scores[$chunk->id] ?? null;
            $scoreStr = $score !== null ? sprintf(' [score: %.3f]', $score) : '';
            $output .= "[Doc {$chunk->document_id}: {$docTitle}] Chunk {$chunk->chunk_index}{$scoreStr}\n";
            $output .= $chunk->getPreview(300)."\n\n";
        }

        if (! empty($ragResult->citations)) {
            $output .= "\nSources:\n";
            foreach ($ragResult->citations as $citation) {
                $output .= "- {$citation['citation']}\n";
            }
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Keyword-based fallback search.
     *
     * @param  \Illuminate\Support\Collection<int, Document>  $documents
     */
    protected function keywordSearch(string $query, \Illuminate\Support\Collection $documents, int $maxResults): ToolResult
    {
        $chunks = DocumentChunk::query()
            ->whereIn('document_id', $documents->pluck('id'))
            ->search($query)
            ->ordered()
            ->limit($maxResults)
            ->with('document')
            ->get();

        if ($chunks->isEmpty()) {
            return new ToolResult(
                success: true,
                output: "No results found for: {$query}",
                error: null
            );
        }

        $output = "Search results for \"{$query}\" (keyword search):\n";
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
