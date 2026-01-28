<?php

namespace App\DTOs;

use App\Models\Conversation;

class ConversationState
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_WAITING_FOR_TOOL = 'waiting_for_tool';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    /**
     * Create a new conversation state instance.
     *
     * @param  array<int, ChatMessage>|null  $newMessages
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $status,
        public readonly ?ToolCall $pendingTool = null,
        public readonly ?array $newMessages = null,
        public readonly ?string $error = null
    ) {}

    /**
     * Create a state for when waiting for a tool result.
     */
    public static function waitingForTool(Conversation $conversation, ToolCall $toolCall): self
    {
        return new self($conversation, self::STATUS_WAITING_FOR_TOOL, $toolCall);
    }

    /**
     * Create a state for when the conversation is completed.
     */
    public static function completed(Conversation $conversation): self
    {
        return new self($conversation, self::STATUS_COMPLETED);
    }

    /**
     * Create a state for when the conversation failed.
     */
    public static function failed(Conversation $conversation, string $error): self
    {
        return new self($conversation, self::STATUS_FAILED, error: $error);
    }

    /**
     * Create a state for when processing.
     */
    public static function processing(Conversation $conversation): self
    {
        return new self($conversation, self::STATUS_PROCESSING);
    }

    /**
     * Create a state for max turns reached.
     */
    public static function maxTurns(Conversation $conversation): self
    {
        return new self($conversation, self::STATUS_COMPLETED);
    }

    /**
     * Convert the state to a polling response.
     *
     * @return array<string, mixed>
     */
    public function toPollingResponse(): array
    {
        $response = [
            'status' => $this->status,
            'conversation_id' => $this->conversation->id,
        ];

        if ($this->pendingTool !== null) {
            $response['tool_request'] = $this->pendingTool->toArray();
            $response['submit_url'] = "/api/v1/conversations/{$this->conversation->id}/tool-results";
        }

        if ($this->newMessages !== null) {
            $response['messages'] = array_map(fn (ChatMessage $msg) => $msg->toArray(), $this->newMessages);
        }

        if ($this->error !== null) {
            $response['error'] = $this->error;
        }

        $response['stats'] = [
            'turns' => $this->conversation->turn_count,
            'tokens' => $this->conversation->total_tokens,
        ];

        return $response;
    }
}
