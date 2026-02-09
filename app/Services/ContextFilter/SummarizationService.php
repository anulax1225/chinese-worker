<?php

declare(strict_types=1);

namespace App\Services\ContextFilter;

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\Exceptions\SummarizationException;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Services\AIBackendManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class SummarizationService
{
    public function __construct(
        private readonly AIBackendManager $backendManager,
        private readonly TokenEstimator $tokenEstimator,
    ) {}

    /**
     * Summarize messages and store in database.
     *
     * @param  array<ChatMessage>  $messages  Messages to summarize
     * @param  array<string, mixed>  $options  Summarization options
     */
    public function summarize(
        Conversation $conversation,
        array $messages,
        array $options = [],
    ): ConversationSummary {
        $startTime = hrtime(true);

        $minMessages = $options['min_messages'] ?? config('ai.summarization.min_messages_for_summary', 5);

        if (count($messages) < $minMessages) {
            throw SummarizationException::insufficientMessages(
                count: count($messages),
                minimum: $minMessages,
                conversationId: $conversation->id,
            );
        }

        $agent = $conversation->agent;
        $prompt = $this->buildPrompt($messages, $options);

        try {
            $summaryContent = $this->callAI($prompt, $agent, $options);
        } catch (Throwable $e) {
            throw SummarizationException::apiFailure(
                message: $e->getMessage(),
                conversationId: $conversation->id,
                backend: $options['backend'] ?? $agent?->ai_backend ?? config('ai.default'),
                previous: $e,
            );
        }

        if (empty(trim($summaryContent))) {
            throw SummarizationException::emptyResponse(
                conversationId: $conversation->id,
                backend: $options['backend'] ?? $agent?->ai_backend ?? config('ai.default'),
            );
        }

        // Calculate token count for summary
        $summaryMessage = ChatMessage::system($summaryContent);
        $summaryTokens = $this->tokenEstimator->estimate($summaryMessage);

        // Calculate original token count
        $originalTokens = 0;
        foreach ($messages as $message) {
            $originalTokens += $message->tokenCount ?? $this->tokenEstimator->estimate($message);
        }

        // Determine position range
        $positions = $this->getMessagePositions($conversation, $messages);
        $fromPosition = min($positions);
        $toPosition = max($positions);

        // Get message IDs
        $messageIds = $this->getMessageIds($conversation, $messages);

        // Determine backend and model used
        $backendName = $options['backend'] ?? $agent?->ai_backend ?? config('ai.default');
        $modelName = $options['model'] ?? config("ai.backends.{$backendName}.model", 'unknown');

        $durationMs = (hrtime(true) - $startTime) / 1e6;

        // Create the summary record
        $summary = ConversationSummary::create([
            'conversation_id' => $conversation->id,
            'from_position' => $fromPosition,
            'to_position' => $toPosition,
            'content' => $summaryContent,
            'token_count' => $summaryTokens,
            'backend_used' => $backendName,
            'model_used' => $modelName,
            'summarized_message_ids' => $messageIds,
            'original_token_count' => $originalTokens,
            'metadata' => [
                'duration_ms' => $durationMs,
                'message_count' => count($messages),
            ],
        ]);

        Log::info('[Summarization] Created summary', [
            'conversation_id' => $conversation->id,
            'summary_id' => $summary->id,
            'message_count' => count($messages),
            'original_tokens' => $originalTokens,
            'summary_tokens' => $summaryTokens,
            'compression_ratio' => $summary->getCompressionRatio(),
            'duration_ms' => $durationMs,
        ]);

        // Invalidate cache
        $this->invalidateCache($conversation);

        return $summary;
    }

    /**
     * Get existing summary for a position range or generate new one.
     *
     * @param  array<ChatMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    public function getOrCreate(
        Conversation $conversation,
        array $messages,
        array $options = [],
    ): ConversationSummary {
        $positions = $this->getMessagePositions($conversation, $messages);
        $fromPosition = min($positions);
        $toPosition = max($positions);

        // Check for existing summary covering this range
        $existing = $this->findExistingSummary($conversation, $fromPosition, $toPosition);

        if ($existing !== null) {
            Log::debug('[Summarization] Using existing summary', [
                'conversation_id' => $conversation->id,
                'summary_id' => $existing->id,
            ]);

            return $existing;
        }

        return $this->summarize($conversation, $messages, $options);
    }

    /**
     * Create a synthetic message from a summary.
     */
    public function createSyntheticMessage(
        ConversationSummary $summary,
        int $position,
    ): Message {
        $content = "[Conversation Summary]\n\n".$summary->content;

        $message = Message::create([
            'conversation_id' => $summary->conversation_id,
            'position' => $position,
            'role' => 'system',
            'content' => $content,
            'is_synthetic' => true,
            'token_count' => $summary->token_count,
            'counted_at' => now(),
        ]);

        Log::debug('[Summarization] Created synthetic message', [
            'conversation_id' => $summary->conversation_id,
            'summary_id' => $summary->id,
            'message_id' => $message->id,
            'position' => $position,
        ]);

        return $message;
    }

    /**
     * Mark messages as summarized.
     *
     * @param  array<string>  $messageIds
     */
    public function markAsSummarized(
        array $messageIds,
        ConversationSummary $summary,
    ): void {
        Message::whereIn('id', $messageIds)->update([
            'summarized' => true,
            'summary_id' => $summary->id,
        ]);

        Log::debug('[Summarization] Marked messages as summarized', [
            'summary_id' => $summary->id,
            'message_count' => count($messageIds),
        ]);
    }

    /**
     * Build the summarization prompt from messages.
     *
     * @param  array<ChatMessage>  $messages
     * @param  array<string, mixed>  $options
     */
    protected function buildPrompt(array $messages, array $options): string
    {
        $targetTokens = $options['target_tokens'] ?? config('ai.summarization.target_tokens', 500);
        $customPrompt = $options['prompt'] ?? config('ai.summarization.prompt');

        $systemInstruction = $customPrompt ?? 'Summarize the following conversation. Preserve key topics, decisions, and context. Be concise.';
        $systemInstruction .= "\n\nTarget length: approximately {$targetTokens} tokens.";

        // Format the conversation for summarization
        $conversationText = $this->formatMessagesForSummary($messages);

        return $systemInstruction."\n\n---\n\n".$conversationText;
    }

    /**
     * Format messages into a text representation for summarization.
     *
     * @param  array<ChatMessage>  $messages
     */
    protected function formatMessagesForSummary(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = ucfirst($message->role);
            $content = $message->content;

            // Handle tool calls
            if (! empty($message->toolCalls)) {
                $toolNames = array_map(
                    fn ($tc) => $tc['name'] ?? 'unknown',
                    $message->toolCalls
                );
                $content .= ' [Called tools: '.implode(', ', $toolNames).']';
            }

            // Handle tool results
            if ($message->role === 'tool') {
                $role = 'Tool';
                if ($message->name) {
                    $role = "Tool ({$message->name})";
                }
            }

            $lines[] = "{$role}: {$content}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Call the AI backend for summarization.
     *
     * @param  array<string, mixed>  $options
     */
    protected function callAI(string $prompt, ?Agent $agent, array $options): string
    {
        $backendName = $options['backend'] ?? $agent?->ai_backend ?? config('ai.summarization.backend') ?? config('ai.default');
        $backend = $this->backendManager->driver($backendName);

        // Create a minimal agent-like context for the AI call
        $systemPrompt = 'You are a summarization assistant. Your task is to create concise, accurate summaries of conversations.';

        $messages = [
            ChatMessage::system($systemPrompt),
            ChatMessage::user($prompt),
        ];

        // Use the backend's execute method with a minimal context
        // We need an agent, so we'll use the conversation's agent or create a minimal config
        if ($agent !== null) {
            $result = $this->backendManager->forAgent($agent);
            $configuredBackend = $result['backend'];

            $response = $configuredBackend->execute($agent, [
                'messages' => $messages,
                'tools' => [],
                'system_prompt' => $systemPrompt,
            ]);
        } else {
            // Fallback: use the backend directly with minimal config
            $response = $backend->execute(
                Agent::factory()->make([
                    'ai_backend' => $backendName,
                    'model' => $options['model'] ?? config("ai.backends.{$backendName}.model"),
                ]),
                [
                    'messages' => $messages,
                    'tools' => [],
                    'system_prompt' => $systemPrompt,
                ]
            );
        }

        return $response->content;
    }

    /**
     * Get message positions from ChatMessages.
     *
     * @param  array<ChatMessage>  $messages
     * @return array<int>
     */
    protected function getMessagePositions(Conversation $conversation, array $messages): array
    {
        // Get all messages from the conversation and match by content
        $dbMessages = $conversation->conversationMessages()->get();
        $positions = [];

        foreach ($messages as $chatMessage) {
            foreach ($dbMessages as $dbMessage) {
                if ($dbMessage->content === $chatMessage->content && $dbMessage->role === $chatMessage->role) {
                    $positions[] = $dbMessage->position;
                    break;
                }
            }
        }

        // Fallback if we couldn't match messages
        if (empty($positions)) {
            return range(0, count($messages) - 1);
        }

        return $positions;
    }

    /**
     * Get message IDs from ChatMessages.
     *
     * @param  array<ChatMessage>  $messages
     * @return array<string>
     */
    protected function getMessageIds(Conversation $conversation, array $messages): array
    {
        $dbMessages = $conversation->conversationMessages()->get();
        $ids = [];

        foreach ($messages as $chatMessage) {
            foreach ($dbMessages as $dbMessage) {
                if ($dbMessage->content === $chatMessage->content && $dbMessage->role === $chatMessage->role) {
                    $ids[] = $dbMessage->id;
                    break;
                }
            }
        }

        return $ids;
    }

    /**
     * Find an existing summary that covers the given position range.
     */
    protected function findExistingSummary(
        Conversation $conversation,
        int $fromPosition,
        int $toPosition,
    ): ?ConversationSummary {
        return ConversationSummary::query()
            ->where('conversation_id', $conversation->id)
            ->where('from_position', '<=', $fromPosition)
            ->where('to_position', '>=', $toPosition)
            ->first();
    }

    /**
     * Invalidate the cache for a conversation's summaries.
     */
    protected function invalidateCache(Conversation $conversation): void
    {
        if (config('ai.summarization.cache.enabled', true)) {
            Cache::forget("conversation_summaries:{$conversation->id}");
        }
    }
}
