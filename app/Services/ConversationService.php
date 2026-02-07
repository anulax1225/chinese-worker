<?php

namespace App\Services;

use App\DTOs\ChatMessage;
use App\DTOs\ConversationState;
use App\DTOs\ToolResult;
use App\Jobs\ProcessConversationTurn;
use App\Models\Conversation;
use Exception;
use Illuminate\Support\Facades\Log;

class ConversationService
{
    public function __construct(
        protected ConversationEventBroadcaster $broadcaster,
        protected AIBackendManager $backendManager
    ) {}

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
            $conversation->addMessage($userMessage->toArray());
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
            $conversation->addMessage($toolMessage->toArray());

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
