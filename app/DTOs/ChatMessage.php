<?php

namespace App\DTOs;

class ChatMessage
{
    public const ROLE_SYSTEM = 'system';

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    /**
     * Create a new chat message instance.
     *
     * @param  array<string, mixed>|null  $toolCalls
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?array $toolCalls = null,
        public readonly ?string $toolCallId = null,
        public readonly ?array $images = null,
        public readonly ?string $thinking = null
    ) {}

    /**
     * Create a system message.
     */
    public static function system(string $content): self
    {
        return new self(self::ROLE_SYSTEM, $content);
    }

    /**
     * Create a user message.
     *
     * @param  array<string>|null  $images  Base64 encoded images for vision
     */
    public static function user(string $content, ?array $images = null): self
    {
        return new self(self::ROLE_USER, $content, images: $images);
    }

    /**
     * Create an assistant message.
     *
     * @param  array<string, mixed>|null  $toolCalls
     */
    public static function assistant(string $content, ?array $toolCalls = null, ?string $thinking = null): self
    {
        return new self(self::ROLE_ASSISTANT, $content, toolCalls: $toolCalls, thinking: $thinking);
    }

    /**
     * Create a tool result message.
     */
    public static function tool(string $content, string $toolCallId): self
    {
        return new self(self::ROLE_TOOL, $content, toolCallId: $toolCallId);
    }

    /**
     * Convert the message to Ollama format.
     *
     * @return array<string, mixed>
     */
    public function toOllama(): array
    {
        $message = [
            'role' => $this->role,
            'content' => $this->content,
        ];

        if ($this->images !== null) {
            $message['images'] = $this->images;
        }

        if ($this->toolCalls !== null) {
            $message['tool_calls'] = $this->toolCalls;
        }

        // For tool result messages, include the tool_call_id
        if ($this->toolCallId !== null) {
            $message['tool_call_id'] = $this->toolCallId;
        }

        // Include thinking for assistant messages (some models use this)
        if ($this->thinking !== null) {
            $message['thinking'] = $this->thinking;
        }

        return $message;
    }

    /**
     * Convert the message to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'content' => $this->content,
            'tool_calls' => $this->toolCalls,
            'tool_call_id' => $this->toolCallId,
            'images' => $this->images,
            'thinking' => $this->thinking,
        ];
    }

    /**
     * Create a ChatMessage from an array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            role: $data['role'],
            content: $data['content'] ?? '',
            toolCalls: $data['tool_calls'] ?? null,
            toolCallId: $data['tool_call_id'] ?? null,
            images: $data['images'] ?? null,
            thinking: $data['thinking'] ?? null
        );
    }
}
