<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class ConversationEventBroadcaster
{
    /**
     * Broadcast an event to all listeners for a conversation.
     *
     * @param  array<string, mixed>  $data
     */
    public function broadcast(Conversation $conversation, string $event, array $data): void
    {
        try {
            $channel = "conversation:{$conversation->id}:events";

            $payload = [
                'event' => $event,
                'conversation_id' => $conversation->id,
                'timestamp' => now()->toISOString(),
                'data' => $data,
            ];

            Redis::publish($channel, json_encode($payload));

            Log::debug('Broadcasted conversation event', [
                'conversation_id' => $conversation->id,
                'event' => $event,
                'channel' => $channel,
            ]);
        } catch (\Exception $e) {
            // Don't fail job if broadcasting fails - SSE is best-effort
            Log::warning('Failed to broadcast conversation event', [
                'conversation_id' => $conversation->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Broadcast a text chunk event (for progressive rendering).
     */
    public function textChunk(Conversation $conversation, string $chunk, string $type = 'content'): void
    {
        $this->broadcast($conversation, 'text_chunk', [
            'chunk' => $chunk,
            'type' => $type, // 'content' or 'thinking'
            'conversation_id' => $conversation->id,
        ]);
    }

    /**
     * Broadcast tool request event.
     *
     * @param  array<string, mixed>  $toolRequest
     */
    public function toolRequest(Conversation $conversation, array $toolRequest): void
    {
        $this->broadcast($conversation, 'tool_request', [
            'status' => 'waiting_for_tool',
            'conversation_id' => $conversation->id,
            'tool_request' => $toolRequest,
            'submit_url' => "/api/v1/conversations/{$conversation->id}/tool-results",
            'stats' => [
                'turns' => $conversation->turn_count,
                'tokens' => $conversation->total_tokens,
            ],
        ]);
    }

    /**
     * Broadcast completion event.
     */
    public function completed(Conversation $conversation): void
    {
        $messages = $conversation->getMessages();
        $lastMessage = end($messages);

        $data = [
            'status' => 'completed',
            'conversation_id' => $conversation->id,
            'stats' => [
                'turns' => $conversation->turn_count,
                'tokens' => $conversation->total_tokens,
            ],
        ];

        if ($lastMessage && $lastMessage['role'] === 'assistant') {
            $data['messages'] = [$lastMessage];
        }

        $this->broadcast($conversation, 'completed', $data);
    }

    /**
     * Broadcast failure event.
     */
    public function failed(Conversation $conversation, string $error): void
    {
        $this->broadcast($conversation, 'failed', [
            'status' => 'failed',
            'conversation_id' => $conversation->id,
            'error' => $error,
            'stats' => [
                'turns' => $conversation->turn_count,
                'tokens' => $conversation->total_tokens,
            ],
        ]);
    }

    /**
     * Broadcast processing event.
     */
    public function processing(Conversation $conversation): void
    {
        $this->broadcast($conversation, 'status_changed', [
            'status' => 'processing',
            'conversation_id' => $conversation->id,
            'stats' => [
                'turns' => $conversation->turn_count,
                'tokens' => $conversation->total_tokens,
            ],
        ]);
    }
}
