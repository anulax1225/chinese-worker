<?php

namespace App\Contracts;

use App\DTOs\AIModel;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\ModelPullProgress;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Models\Agent;

interface AIBackendInterface
{
    /**
     * Create a new instance with the specified configuration.
     * This allows per-agent configuration without mutating the original backend.
     */
    public function withConfig(NormalizedModelConfig $config): static;

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

    /**
     * Check if this backend supports model management operations.
     */
    public function supportsModelManagement(): bool;

    /**
     * Pull/download a model with streaming progress updates.
     *
     * @param  callable(ModelPullProgress): void  $onProgress
     *
     * @throws \RuntimeException If pull fails or not supported
     */
    public function pullModel(string $modelName, callable $onProgress): void;

    /**
     * Delete a model from the backend.
     *
     * @throws \RuntimeException If deletion fails or not supported
     */
    public function deleteModel(string $modelName): void;

    /**
     * Get detailed information about a specific model.
     *
     * @throws \RuntimeException If retrieval fails or not supported
     */
    public function showModel(string $modelName): AIModel;

    /**
     * Count the number of tokens in a text string.
     *
     * @param  string  $text  The text to tokenize
     * @return int The number of tokens
     */
    public function countTokens(string $text): int;

    /**
     * Get the context limit (max tokens) for the current model.
     *
     * @return int The maximum context length in tokens
     */
    public function getContextLimit(): int;
}
