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
        Log::info('[ConversationService] processMessage called', [
            'conversation_id' => $conversation->id,
            'message_length' => strlen($message),
            'has_images' => ! empty($images),
            'timestamp' => now()->toIso8601String(),
        ]);

        try {
            // Add user message to conversation
            $userMessage = ChatMessage::user($message, $images);
            $conversation->addMessage($userMessage->toArray());
            $conversation->update(['status' => 'active']);
            $conversation->markAsStarted();

            // Reset request turn count for new user message
            $conversation->resetRequestTurnCount();

            Log::info('[ConversationService] Dispatching ProcessConversationTurn job', [
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Dispatch job to process the turn
            ProcessConversationTurn::dispatch($conversation);

            Log::info('[ConversationService] Job dispatched, broadcasting processing status', [
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Broadcast processing status for SSE clients
            $this->broadcaster->processing($conversation);

            Log::info('[ConversationService] processMessage completed', [
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toIso8601String(),
            ]);

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
        Log::info('[ConversationService] submitToolResult called', [
            'conversation_id' => $conversation->id,
            'call_id' => $callId,
            'success' => $result->success,
            'output_length' => strlen($result->output),
            'has_error' => ! empty($result->error),
            'timestamp' => now()->toIso8601String(),
        ]);

        try {
            // Add tool result to conversation
            // Use error as content when output is empty (refusal/failure cases)
            $content = $result->output !== '' ? $result->output : ($result->error ?? '');
            $toolMessage = ChatMessage::tool($content, $callId);
            $conversation->addMessage($toolMessage->toArray());

            // Check if user refused tool execution or tool failed - stop the loop and wait for new input
            if ($this->isToolRefused($result) || $this->isToolFailed($result)) {
                Log::info('[ConversationService] Tool refused or failed, completing conversation', [
                    'conversation_id' => $conversation->id,
                    'refused' => $this->isToolRefused($result),
                    'failed' => $this->isToolFailed($result),
                ]);

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

            Log::info('[ConversationService] Dispatching next ProcessConversationTurn job after tool result', [
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Dispatch job to process the next turn
            ProcessConversationTurn::dispatch($conversation);

            Log::info('[ConversationService] Job dispatched after tool result, broadcasting processing', [
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toIso8601String(),
            ]);

            // Broadcast processing status for SSE clients
            $this->broadcaster->processing($conversation);

            Log::info('[ConversationService] submitToolResult completed', [
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toIso8601String(),
            ]);

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
