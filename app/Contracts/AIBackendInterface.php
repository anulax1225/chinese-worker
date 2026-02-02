<?php

namespace App\Contracts;

use App\DTOs\AIResponse;
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
}
