<?php

namespace App\Services\Tools;

use App\DTOs\ToolResult;
use App\Models\Conversation;
use App\Models\MessageEmbedding;
use App\Services\RAG\EmbeddingService;

class ConversationMemoryToolHandler
{
    public function __construct(
        protected Conversation $conversation,
        protected EmbeddingService $embeddingService,
    ) {}

    /**
     * Execute a conversation memory tool by name.
     *
     * @param  array<string, mixed>  $args
     */
    public function execute(string $toolName, array $args): ToolResult
    {
        return match ($toolName) {
            'conversation_recall' => $this->recall($args),
            'conversation_memory_status' => $this->status(),
            default => new ToolResult(
                success: false,
                output: '',
                error: "Unknown memory tool: {$toolName}"
            ),
        };
    }

    /**
     * Search conversation history using semantic search.
     *
     * @param  array<string, mixed>  $args
     */
    protected function recall(array $args): ToolResult
    {
        $query = $args['query'] ?? null;
        if (! $query || \strlen($query) < 2) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Search query must be at least 2 characters'
            );
        }

        $maxResults = min($args['max_results'] ?? 5, 10);
        $threshold = $args['threshold'] ?? 0.3;

        // Check if RAG is enabled
        if (! config('ai.rag.enabled', false)) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'Conversation memory search requires RAG to be enabled'
            );
        }

        // Check if we have any embeddings for this conversation
        $embeddingCount = MessageEmbedding::query()
            ->forConversation($this->conversation->id)
            ->withEmbeddings()
            ->count();

        if ($embeddingCount === 0) {
            return new ToolResult(
                success: true,
                output: 'No message embeddings available for this conversation. Messages need to be embedded first.',
                error: null
            );
        }

        // Generate query embedding
        $queryEmbedding = $this->embeddingService->embed($query);

        // Search for similar messages
        $results = MessageEmbedding::query()
            ->forConversation($this->conversation->id)
            ->withEmbeddings()
            ->with('message')
            ->semanticSearch($queryEmbedding, $maxResults, $threshold)
            ->get();

        if ($results->isEmpty()) {
            return new ToolResult(
                success: true,
                output: "No relevant messages found for: \"{$query}\"",
                error: null
            );
        }

        // Record access for analytics
        foreach ($results as $result) {
            $result->recordAccess();
        }

        // Format results
        $output = "Recalled messages for \"{$query}\":\n";
        $output .= str_repeat('-', 60)."\n";

        foreach ($results as $embedding) {
            $message = $embedding->message;
            if (! $message) {
                continue;
            }

            $role = ucfirst($message->role);
            $similarity = round($embedding->similarity ?? 0, 3);
            $position = $message->position;
            $timestamp = $message->created_at?->format('Y-m-d H:i');

            $output .= "[{$role} @ position {$position}] (similarity: {$similarity})\n";
            if ($timestamp) {
                $output .= "Time: {$timestamp}\n";
            }
            $output .= $this->truncateContent($message->content, 500)."\n\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Get memory status for this conversation.
     */
    protected function status(): ToolResult
    {
        $totalMessages = $this->conversation->conversationMessages()
            ->whereIn('role', ['user', 'assistant'])
            ->count();

        $embeddedCount = MessageEmbedding::query()
            ->forConversation($this->conversation->id)
            ->withEmbeddings()
            ->count();

        $pendingCount = $totalMessages - $embeddedCount;
        $ragEnabled = config('ai.rag.enabled', false);

        $output = "Conversation Memory Status:\n";
        $output .= str_repeat('-', 40)."\n";
        $output .= 'RAG Enabled: '.($ragEnabled ? 'Yes' : 'No')."\n";
        $output .= "Total Messages: {$totalMessages}\n";
        $output .= "Embedded: {$embeddedCount}\n";
        $output .= "Pending: {$pendingCount}\n";

        if ($totalMessages > 0) {
            $percentage = round(($embeddedCount / $totalMessages) * 100, 1);
            $output .= "Completion: {$percentage}%\n";
        }

        return new ToolResult(
            success: true,
            output: trim($output),
            error: null
        );
    }

    /**
     * Truncate content with ellipsis.
     */
    protected function truncateContent(?string $content, int $maxLength): string
    {
        if ($content === null) {
            return '';
        }

        if (\strlen($content) <= $maxLength) {
            return $content;
        }

        return substr($content, 0, $maxLength - 3).'...';
    }
}
