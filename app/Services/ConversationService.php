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
        protected ConversationEventBroadcaster $broadcaster
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
            // Add user message to conversation
            $userMessage = ChatMessage::user($message, $images);
            $conversation->addMessage($userMessage->toArray());
            $conversation->update(['status' => 'active']);
            $conversation->markAsStarted();

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
            // Add tool result to conversation
            $toolMessage = ChatMessage::tool($result->output ?? $result->error ?? '', $callId);
            $conversation->addMessage($toolMessage->toArray());

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
}
