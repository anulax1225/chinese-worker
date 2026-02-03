<?php

namespace App\Contracts;

use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\ToolCall;
use App\Models\Agent;

interface AIBackendInterface
{
    /**
     * Execute an agent with the given context.
     */
    public function execute(Agent $agent, array $context): AIResponse;

    /**
     * Execute an agent with streaming response.
     */
    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse;

    /**
     * Validate the backend configuration.
     */
    public function validateConfig(array $config): bool;

    /**
     * Get the backend's capabilities.
     *
     * @return array<string, mixed>
     */
    public function getCapabilities(): array;

    /**
     * List available models for this backend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listModels(): array;

    /**
     * Disconnect and cleanup any open connections.
     */
    public function disconnect(): void;

    /**
     * Format a ChatMessage for this backend's API format.
     *
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message): array;

    /**
     * Parse a tool call from this backend's response format.
     *
     * @param  array<string, mixed>  $data
     */
    public function parseToolCall(array $data): ToolCall;
}
