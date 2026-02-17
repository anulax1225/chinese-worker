<?php

namespace App\Services\AI;

use App\Contracts\AIBackendInterface;
use App\DTOs\AIModel;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Models\Agent;
use RuntimeException;

class FakeBackend implements AIBackendInterface
{
    protected string $model;

    protected int $embeddingDimensions;

    protected ?NormalizedModelConfig $normalizedConfig = null;

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(array $config = [])
    {
        $this->model = $config['model'] ?? 'test-model';
        $this->embeddingDimensions = $config['embedding_dimensions'] ?? 4;
    }

    public function withConfig(NormalizedModelConfig $config): static
    {
        $clone = clone $this;
        $clone->normalizedConfig = $config;
        $clone->model = $config->model;

        return $clone;
    }

    public function execute(Agent $agent, array $context): AIResponse
    {
        return new AIResponse(
            content: 'This is a fake response.',
            model: $this->model,
            tokensUsed: 10,
            finishReason: 'stop',
            toolCalls: [],
            metadata: [
                'prompt_tokens' => 5,
                'completion_tokens' => 5,
                'total_tokens' => 10,
            ],
        );
    }

    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        $content = 'This is a fake response.';
        $callback($content, 'content');

        return new AIResponse(
            content: $content,
            model: $this->model,
            tokensUsed: 10,
            finishReason: 'stop',
            toolCalls: [],
            metadata: [
                'prompt_tokens' => 5,
                'completion_tokens' => 5,
                'total_tokens' => 10,
            ],
        );
    }

    public function validateConfig(array $config): bool
    {
        return true;
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'function_calling' => true,
            'vision' => true,
            'embeddings' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listModels(bool $detailed = false): array
    {
        $result = [
            'name' => $this->model,
        ];

        if ($detailed) {
            $result['family'] = 'fake';
            $result['capabilities'] = ['completion', 'vision', 'tool_use', 'embeddings'];
            $result['context_length'] = 4096;
            $result['description'] = 'Fake model for testing';
        }

        return [$result];
    }

    public function disconnect(): void
    {
        // No-op
    }

    /**
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message): array
    {
        $formatted = [
            'role' => $message->role,
            'content' => $message->content,
        ];

        if ($message->toolCallId !== null) {
            $formatted['tool_call_id'] = $message->toolCallId;
        }

        if ($message->toolCalls !== null) {
            $formatted['tool_calls'] = $message->toolCalls;
        }

        return $formatted;
    }

    public function parseToolCall(array $data): ToolCall
    {
        $function = $data['function'] ?? [];
        $arguments = $function['arguments'] ?? '{}';

        if (is_string($arguments)) {
            $parsedArgs = json_decode($arguments, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $parsedArgs = [];
            }
        } else {
            $parsedArgs = $arguments;
        }

        return new ToolCall(
            id: $data['id'] ?? uniqid('call_'),
            name: $function['name'] ?? '',
            arguments: $parsedArgs
        );
    }

    public function supportsModelManagement(): bool
    {
        return false;
    }

    public function pullModel(string $modelName, callable $onProgress): void
    {
        throw new RuntimeException('Model management is not supported for the fake backend');
    }

    public function deleteModel(string $modelName): void
    {
        throw new RuntimeException('Model management is not supported for the fake backend');
    }

    public function showModel(string $modelName): AIModel
    {
        return new AIModel(
            name: $this->model,
            family: 'fake',
            capabilities: ['completion', 'vision', 'tool_use', 'embeddings'],
            contextLength: 4096,
            description: 'Fake model for testing',
        );
    }

    public function countTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / 4);
    }

    public function getContextLimit(): int
    {
        return $this->normalizedConfig?->contextLength ?? 4096;
    }

    /**
     * @param  array<string>  $texts
     * @return array<array<float>>
     */
    public function generateEmbeddings(array $texts, ?string $model = null): array
    {
        $embeddings = [];

        foreach ($texts as $index => $text) {
            $embedding = [];
            for ($i = 0; $i < $this->embeddingDimensions; $i++) {
                // Deterministic but varied per text: use index and dimension
                $embedding[] = round(($index + 1) * 0.1 + $i * 0.1, 4);
            }
            $embeddings[] = $embedding;
        }

        return $embeddings;
    }

    public function getEmbeddingDimensions(?string $model = null): int
    {
        return $this->embeddingDimensions;
    }
}
