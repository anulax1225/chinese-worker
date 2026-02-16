<?php

namespace App\Services\RAG;

use App\DTOs\RetrievalResult;
use Illuminate\Support\Collection;

class RAGContextBuilder
{
    /**
     * Build context from retrieved chunks for injection into prompts.
     *
     * @param  RetrievalResult  $result  The retrieval result
     * @param  string  $query  The original query
     * @param  array<string, mixed>  $options  Formatting options
     * @return string The formatted context
     */
    public function build(RetrievalResult $result, string $query, array $options = []): string
    {
        if (! $result->hasChunks()) {
            return '';
        }

        $maxTokens = $options['max_context_tokens'] ?? config('ai.rag.max_context_tokens', 4000);
        $includeMetadata = $options['include_metadata'] ?? true;
        $includeCitations = $options['include_citations'] ?? true;

        $chunks = $this->selectChunksWithinBudget($result->chunks, $maxTokens);

        if ($chunks->isEmpty()) {
            return '';
        }

        $parts = [];

        // Header
        $parts[] = '## Retrieved Context';
        $parts[] = '';
        $parts[] = "The following information was retrieved from documents to help answer the query: \"{$query}\"";
        $parts[] = '';

        // Format each chunk
        foreach ($chunks as $index => $chunk) {
            $citation = $includeCitations ? $this->formatCitation($chunk, $index + 1) : null;
            $parts[] = $this->formatChunk($chunk, $citation, $includeMetadata);
            $parts[] = '';
        }

        // Footer with citation list
        if ($includeCitations) {
            $parts[] = '---';
            $parts[] = '### Sources';
            foreach ($chunks as $index => $chunk) {
                $parts[] = $this->formatSourceReference($chunk, $index + 1);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Format context for injection into system prompt.
     *
     * @param  Collection  $chunks  Collection of DocumentChunk models
     * @param  string  $query  The original query
     * @return string The formatted context
     */
    public function formatForPrompt(Collection $chunks, string $query): string
    {
        $result = new RetrievalResult(
            chunks: $chunks,
            strategy: 'direct',
        );

        return $this->build($result, $query);
    }

    /**
     * Select chunks within token budget.
     */
    protected function selectChunksWithinBudget(Collection $chunks, int $maxTokens): Collection
    {
        $selected = collect();
        $totalTokens = 0;
        $overheadPerChunk = 50; // Approximate tokens for formatting

        foreach ($chunks as $chunk) {
            $chunkTokens = $chunk->token_count + $overheadPerChunk;

            if ($totalTokens + $chunkTokens > $maxTokens) {
                break;
            }

            $selected->push($chunk);
            $totalTokens += $chunkTokens;
        }

        return $selected;
    }

    /**
     * Format a single chunk for display.
     *
     * @param  mixed  $chunk  DocumentChunk model
     * @param  string|null  $citation  Citation marker
     * @param  bool  $includeMetadata  Whether to include metadata
     */
    protected function formatChunk($chunk, ?string $citation, bool $includeMetadata): string
    {
        $parts = [];

        // Citation marker
        if ($citation) {
            $parts[] = $citation;
        }

        // Section title if available
        if ($includeMetadata && ! empty($chunk->section_title)) {
            $parts[] = "**Section:** {$chunk->section_title}";
        }

        // Content
        $parts[] = '';
        $parts[] = $chunk->content;

        return implode("\n", $parts);
    }

    /**
     * Format citation marker.
     *
     * @param  mixed  $chunk  DocumentChunk model
     * @param  int  $index  Citation index
     */
    protected function formatCitation($chunk, int $index): string
    {
        $docName = $chunk->document?->filename ?? 'Unknown Document';

        return "**[{$index}] {$docName}**";
    }

    /**
     * Format source reference for citation list.
     *
     * @param  mixed  $chunk  DocumentChunk model
     * @param  int  $index  Citation index
     */
    protected function formatSourceReference($chunk, int $index): string
    {
        $docName = $chunk->document?->filename ?? 'Unknown Document';
        $sectionInfo = $chunk->section_title ? " → {$chunk->section_title}" : '';
        $chunkInfo = "(Chunk {$chunk->chunk_index})";

        return "[{$index}] {$docName}{$sectionInfo} {$chunkInfo}";
    }

    /**
     * Extract citations from chunks.
     *
     * @return array<array{citation: string, excerpt: string, chunk_id: int, document_id: int}>
     */
    public function extractCitations(Collection $chunks): array
    {
        return $chunks->map(function ($chunk, $index) {
            return [
                'citation' => $this->formatSourceReference($chunk, $index + 1),
                'excerpt' => mb_substr($chunk->content, 0, 200).'...',
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_id,
            ];
        })->toArray();
    }

    /**
     * Get total token count of chunks.
     */
    public function getTotalTokens(Collection $chunks): int
    {
        return $chunks->sum('token_count');
    }
}
