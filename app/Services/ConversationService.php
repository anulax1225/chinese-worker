<?php

namespace App\Services;

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\DTOs\ConversationState;
use App\DTOs\ToolResult;
use App\Events\ContextFiltered;
use App\Jobs\ProcessConversationTurn;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ContextFilter\ContextFilterManager;
use Exception;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    public function __construct(
        protected ConversationEventBroadcaster $broadcaster,
        protected AIBackendManager $backendManager,
        protected ContextFilterManager $contextFilterManager,
        protected TokenEstimator $tokenEstimator,
    ) {}

    /**
     * Get messages for AI, applying context filtering if needed.
     *
     * @return array<int, ChatMessage>
     */
    public function getMessagesForAI(
        Conversation $conversation,
        bool $forceFilter = false,
        bool $skipFilter = false,
        int $maxOutputTokens = 4096,
        int $toolDefinitionTokens = 0,
    ): array {
        $messages = $conversation->getMessages();

        if ($skipFilter) {
            return $messages;
        }

        $threshold = $conversation->agent?->context_threshold
            ?? config('ai.context_filter.default_threshold', 0.8);

        $shouldFilter = $forceFilter || $conversation->isApproachingContextLimit($threshold);

        if (! $shouldFilter) {
            return $messages;
        }

        $usageBefore = $conversation->getContextUsagePercentage();

        $result = $this->contextFilterManager->filterForConversation(
            conversation: $conversation,
            maxOutputTokens: $maxOutputTokens,
            toolDefinitionTokens: $toolDefinitionTokens,
        );

        // Calculate usage after filtering
        $usageAfter = $this->estimateFilteredUsage($result->messages, $conversation);

        // Emit observability event
        event(new ContextFiltered(
            conversationId: $conversation->id,
            strategyUsed: $result->strategyUsed,
            originalCount: $result->originalCount,
            filteredCount: $result->filteredCount,
            removedMessageIds: $result->removedMessageIds,
            contextUsageBefore: $usageBefore,
            contextUsageAfter: $usageAfter,
            durationMs: $result->durationMs,
        ));

        Log::info("[ContextFilter] Filtered conversation {$conversation->id}: {$result->originalCount} → {$result->filteredCount} messages using {$result->strategyUsed} in {$result->durationMs}ms");

        return $result->messages;
    }

    /**
     * Estimate context usage for filtered messages.
     *
     * @param  array<int, ChatMessage>  $messages
     */
    protected function estimateFilteredUsage(array $messages, Conversation $conversation): float
    {
        $totalTokens = 0;

        foreach ($messages as $message) {
            $totalTokens += $message->tokenCount ?? $this->tokenEstimator->estimate($message);
        }

        $contextLimit = $conversation->context_limit ?? 128000;

        if ($contextLimit === 0) {
            return 0.0;
        }

        return ($totalTokens / $contextLimit) * 100;
    }

    /**
     * Process a user message by dispatching a job.
     */
    public function processMessage(
        Conversation $conversation,
        string $message,
        ?array $images = null
    ): ConversationState {
        try {
            // Tokenize user message
            $result = $this->backendManager->forAgent($conversation->agent);
            $backend = $result['backend'];
            $tokenCount = $backend->countTokens($message);

            // Set context limit on first message
            if ($conversation->context_limit === null) {
                $conversation->update(['context_limit' => $backend->getContextLimit()]);
            }

            // Add user message to conversation with token count
            $userMessage = ChatMessage::user($message, $images)->withTokenCount($tokenCount);
            $conversation->addMessage($userMessage);
            $conversation->update(['status' => 'active']);
            $conversation->markAsStarted();

            // Reset request turn count for new user message
            $conversation->resetRequestTurnCount();

            // Dispatch job to process the turn
            ProcessConversationTurn::dispatch($conversation);

            // Broadcast processing status for SSE clients
            $this->broadcaster->processing($conversation);

            // Return processing state - CLI will poll for updates
            return ConversationState::processing($conversation);
        } catch (Exception $e) {
            Log::error('Conversation processing failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $conversation->update(['status' => 'failed']);
            $this->broadcaster->failed($conversation, $e->getMessage());

            return ConversationState::failed($conversation, $e->getMessage());
        }
    }

    /**
     * Add a user message to the conversation without dispatching job.
     */
    public function addUserMessage(
        Conversation $conversation,
        string $message,
        ?array $images = null
    ): Message {
        $result = $this->backendManager->forAgent($conversation->agent);
        $backend = $result['backend'];
        $tokenCount = $backend->countTokens($message);

        $userMessage = ChatMessage::user($message, $images)->withTokenCount($tokenCount);

        return $conversation->addMessage($userMessage);
    }

    /**
     * Start processing the conversation (dispatch job).
     */
    public function startProcessing(Conversation $conversation): ConversationState
    {
        try {
            if ($conversation->context_limit === null) {
                $result = $this->backendManager->forAgent($conversation->agent);
                $backend = $result['backend'];
                $conversation->update(['context_limit' => $backend->getContextLimit()]);
            }

            $conversation->update(['status' => 'active']);
            $conversation->markAsStarted();
            $conversation->resetRequestTurnCount();

            ProcessConversationTurn::dispatch($conversation);
            $this->broadcaster->processing($conversation);

            return ConversationState::processing($conversation);
        } catch (Exception $e) {
            Log::error('Conversation processing failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
            $conversation->update(['status' => 'failed']);
            $this->broadcaster->failed($conversation, $e->getMessage());

            return ConversationState::failed($conversation, $e->getMessage());
        }
    }

    /**
     * Submit a tool result and resume the conversation.
     */
    public function submitToolResult(
        Conversation $conversation,
        string $callId,
        ToolResult $result
    ): ConversationState {
        try {
            // Add tool result to conversation with token count
            // Use error as content when output is empty (refusal/failure cases)
            $content = $result->output !== '' ? $result->output : ($result->error ?? '');

            // Tokenize tool result
            $backendResult = $this->backendManager->forAgent($conversation->agent);
            $backend = $backendResult['backend'];
            $tokenCount = $backend->countTokens($content);

            $toolMessage = ChatMessage::tool($content, $callId)->withTokenCount($tokenCount);
            $conversation->addMessage($toolMessage);

            // Check if user refused tool execution or tool failed - stop the loop and wait for new input
            if ($this->isToolRefused($result) || $this->isToolFailed($result)) {
                // Mark conversation as completed to stop the agentic loop
                $conversation->update([
                    'status' => 'completed',
                    'waiting_for' => 'none',
                    'pending_tool_request' => null,
                ]);

                // Broadcast completion so CLI returns to prompt
                $this->broadcaster->completed($conversation);

                return ConversationState::completed($conversation);
            }

            // Update conversation state
            $conversation->update([
                'status' => 'active',
                'waiting_for' => 'none',
                'pending_tool_request' => null,
            ]);

            // Dispatch job to process the next turn
            ProcessConversationTurn::dispatch($conversation);

            // Broadcast processing status for SSE clients
            $this->broadcaster->processing($conversation);

            // Return processing state - CLI will poll for updates
            return ConversationState::processing($conversation);
        } catch (Exception $e) {
            Log::error('Tool result submission failed', [
                'conversation_id' => $conversation->id,
                'call_id' => $callId,
                'error' => $e->getMessage(),
            ]);

            $conversation->update(['status' => 'failed']);
            $this->broadcaster->failed($conversation, $e->getMessage());

            return ConversationState::failed($conversation, $e->getMessage());
        }
    }

    /**
     * Check if the tool result indicates user refused execution.
     */
    protected function isToolRefused(ToolResult $result): bool
    {
        if ($result->success) {
            return false;
        }

        $error = $result->error ?? '';

        return str_contains($error, '[User refused tool execution]');
    }

    /**
     * Check if the tool result indicates tool failed.
     */
    protected function isToolFailed(ToolResult $result): bool
    {
        if ($result->success) {
            return false;
        }

        $error = $result->error ?? '';

        return str_contains($error, '[Tool failed:');
    }
}
