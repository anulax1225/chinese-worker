<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\Strategies;

use App\Contracts\ContextFilterStrategy;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\DTOs\FilterResult;
use App\Enums\SummaryStatus;
use App\Models\ConversationSummary;
use Illuminate\Support\Facades\Log;

class SummaryBoundaryStrategy implements ContextFilterStrategy
{
    /**
     * Strategy identifier.
     */
    public function name(): string
    {
        return 'summary_boundary';
    }

    /**
     * Validate strategy options.
     *
     * @param  array<string, mixed>  $options
     */
    public function validateOptions(array $options): void
    {
        // No options to validate for this strategy
    }

    /**
     * Filter messages by clipping at the latest completed summary boundary.
     *
     * This strategy:
     * 1. Finds the latest completed summary for the conversation
     * 2. Returns: system prompt + summary as first user message + messages after summary's to_position
     * 3. If no summary exists, passes through unchanged
     */
    public function filter(FilterContext $context): FilterResult
    {
        $startTime = hrtime(true);
        $messages = $context->messages;

        // No conversation context - can't look up summaries
        if ($context->conversation === null) {
            Log::debug('[SummaryBoundaryStrategy] No conversation context, passing through');

            return FilterResult::noOp($messages, $this->name());
        }

        // Find the latest completed summary
        $summary = $this->getLatestCompletedSummary($context->conversation->id);

        if ($summary === null) {
            Log::debug('[SummaryBoundaryStrategy] No completed summaries found, passing through', [
                'conversation_id' => $context->conversation->id,
            ]);

            return FilterResult::noOp($messages, $this->name());
        }

        // Build filtered message list
        $filteredMessages = $this->buildFilteredMessages($messages, $summary);
        $removedIds = $this->getRemovedMessageIds($messages, $summary);

        $durationMs = (hrtime(true) - $startTime) / 1e6;

        Log::info('[SummaryBoundaryStrategy] Clipped at summary boundary', [
            'conversation_id' => $context->conversation->id,
            'summary_id' => $summary->id,
            'boundary_position' => $summary->to_position,
            'original_count' => count($messages),
            'filtered_count' => count($filteredMessages),
            'removed_count' => count($removedIds),
        ]);

        return new FilterResult(
            messages: $filteredMessages,
            originalCount: count($messages),
            filteredCount: count($filteredMessages),
            removedMessageIds: $removedIds,
            strategyUsed: $this->name(),
            durationMs: $durationMs,
            metadata: [
                'summary_id' => $summary->id,
                'boundary_position' => $summary->to_position,
                'compression_ratio' => $summary->getCompressionRatio(),
            ],
        );
    }

    /**
     * Get the latest completed summary for a conversation.
     */
    private function getLatestCompletedSummary(int $conversationId): ?ConversationSummary
    {
        return ConversationSummary::query()
            ->where('conversation_id', $conversationId)
            ->where('status', SummaryStatus::Completed)
            ->orderByDesc('to_position')
            ->first();
    }

    /**
     * Build the filtered message list with summary injected.
     *
     * @param  array<ChatMessage>  $messages
     * @return array<ChatMessage>
     */
    private function buildFilteredMessages(array $messages, ConversationSummary $summary): array
    {
        $result = [];

        // Keep system prompt if present
        if (! empty($messages) && $messages[0]->role === 'system') {
            $result[] = $messages[0];
        }

        // Add summary as first user message (so the assistant sees it as context)
        $summaryMessage = ChatMessage::user(
            "[Previous Conversation Summary]\n\n{$summary->content}"
        )->withTokenCount($summary->token_count);

        $result[] = $summaryMessage;

        // Add messages after the summary boundary
        foreach ($messages as $message) {
            // Skip system prompt (already added)
            if ($message->role === 'system' && $result[0]->role === 'system') {
                continue;
            }

            // Get position from metadata or use index as fallback
            $position = $message->metadata['position'] ?? null;

            // If position is available and is after the summary boundary, include it
            if ($position !== null && $position > $summary->to_position) {
                $result[] = $message;
            }
        }

        return $result;
    }

    /**
     * Get IDs of messages that were removed (those within the summary boundary).
     *
     * @param  array<ChatMessage>  $messages
     * @return array<string>
     */
    private function getRemovedMessageIds(array $messages, ConversationSummary $summary): array
    {
        $removed = [];

        foreach ($messages as $message) {
            // Skip system prompt
            if ($message->role === 'system') {
                continue;
            }

            $position = $message->metadata['position'] ?? null;
            $id = $message->metadata['id'] ?? null;

            // Messages at or before the summary boundary are considered removed
            if ($position !== null && $position <= $summary->to_position && $id !== null) {
                $removed[] = $id;
            }
        }

        return $removed;
    }
}
