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
        public readonly ?string $thinking = null,
        public readonly ?string $name = null,
        public readonly ?int $tokenCount = null,
        public readonly ?string $countedAt = null
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
    public static function tool(string $content, string $toolCallId, ?string $name = null): self
    {
        return new self(self::ROLE_TOOL, $content, toolCallId: $toolCallId, name: $name);
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
            'name' => $this->name,
            'token_count' => $this->tokenCount,
            'counted_at' => $this->countedAt,
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
            thinking: $data['thinking'] ?? null,
            name: $data['name'] ?? null,
            tokenCount: $data['token_count'] ?? null,
            countedAt: $data['counted_at'] ?? null
        );
    }

    /**
     * Create a new instance with token count information.
     */
    public function withTokenCount(int $tokenCount, ?string $countedAt = null): self
    {
        return new self(
            role: $this->role,
            content: $this->content,
            toolCalls: $this->toolCalls,
            toolCallId: $this->toolCallId,
            images: $this->images,
            thinking: $this->thinking,
            name: $this->name,
            tokenCount: $tokenCount,
            countedAt: $countedAt ?? now()->toISOString()
        );
    }
}
