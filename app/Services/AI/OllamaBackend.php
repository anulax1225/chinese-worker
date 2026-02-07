<?php

namespace App\Services\AI;

use App\Contracts\AIBackendInterface;
use App\DTOs\AIModel;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\ModelPullProgress;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Models\Agent;
use App\Models\Tool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class OllamaBackend implements AIBackendInterface
{
    protected Client $client;

    protected string $baseUrl;

    protected string $model;

    protected int $timeout;

    protected array $options;

    protected ?NormalizedModelConfig $normalizedConfig = null;

    public function __construct(array $config)
    {
        if (! $this->validateConfig($config)) {
            throw new InvalidArgumentException('Invalid Ollama configuration');
        }

        $this->baseUrl = $config['base_url'];
        $this->model = $config['model'];
        $this->timeout = $config['timeout'] ?? 5 * 60 * 60 * 60;
        $this->options = $config['options'] ?? [];

        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function withConfig(NormalizedModelConfig $config): static
    {
        $clone = clone $this;
        $clone->normalizedConfig = $config;
        $clone->model = $config->model;
        $clone->timeout = $config->timeout;
        $clone->options = $config->toOllamaOptions();

        // Recreate client with new timeout
        $clone->client = new Client([
            'base_uri' => $clone->baseUrl,
            'timeout' => $clone->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);

        return $clone;
    }

    public function execute(Agent $agent, array $context): AIResponse
    {
        try {
            $messages = $this->buildMessages($agent, $context);
            // Use tools from context if provided (from ConversationService), otherwise build from agent
            $tools = $context['tools'] ?? $this->buildTools($agent);
            // Convert to Ollama format
            $tools = $this->convertToolsToOllamaFormat($tools);

            $payload = [
                'model' => $this->model,
                'messages' => array_map(fn (ChatMessage $m) => $this->formatMessage($m), $messages),
                'stream' => false,
                'options' => $this->options,
            ];

            if (! empty($tools)) {
                $payload['tools'] = $tools;
            }

            $response = $this->client->post('/api/chat', [
                'json' => $payload,
                'timeout' => $this->timeout,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Ollama API request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        try {
            $messages = $this->buildMessages($agent, $context);
            // Use tools from context if provided (from ConversationService), otherwise build from agent
            $tools = $context['tools'] ?? $this->buildTools($agent);
            // Convert to Ollama format
            $tools = $this->convertToolsToOllamaFormat($tools);

            $payload = [
                'model' => $this->model,
                'messages' => array_map(fn (ChatMessage $m) => $this->formatMessage($m), $messages),
                'stream' => true,
                'options' => $this->options,
            ];

            if (! empty($tools)) {
                $payload['tools'] = $tools;
            }

            Log::info('Ollama request payload', [
                'model' => $this->model,
                'tools_count' => count($tools),
                'tools' => $tools,
                'message_count' => count($messages),
            ]);

            $response = $this->client->post('/api/chat', [
                'json' => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();
            $fullContent = '';
            $fullThinking = '';
            $lastData = [];
            $toolCalls = [];

            try {
                while (! $body->eof()) {
                    $line = $this->readLine($body);

                    if (empty($line)) {
                        continue;
                    }

                    $data = json_decode($line, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    // Handle thinking streaming (before content)
                    if (isset($data['message']['thinking']) && $data['message']['thinking'] !== '') {
                        $thinking = $data['message']['thinking'];
                        $fullThinking .= $thinking;
                        $callback($thinking, 'thinking');
                    }

                    // Handle content streaming
                    if (isset($data['message']['content']) && $data['message']['content'] !== '') {
                        $content = $data['message']['content'];
                        $fullContent .= $content;
                        $callback($content, 'content');
                    }

                    // Collect tool calls
                    if (isset($data['message']['tool_calls'])) {
                        Log::info('Ollama stream tool_calls received', [
                            'raw_tool_calls' => $data['message']['tool_calls'],
                        ]);
                        $toolCalls = array_merge($toolCalls, $data['message']['tool_calls']);
                    }

                    if (! empty($data['done'])) {
                        $lastData = $data;
                        break;
                    }
                }

                return $this->buildAIResponse($fullContent, $lastData, $toolCalls, $fullThinking ?: null);
            } finally {
                $body->close();
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Ollama streaming request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function validateConfig(array $config): bool
    {
        return isset($config['base_url'])
            && isset($config['model'])
            && filter_var($config['base_url'], FILTER_VALIDATE_URL) !== false;
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

    public function listModels(): array
    {
        try {
            $response = $this->client->get('/api/tags');
            $data = json_decode($response->getBody()->getContents(), true);

            return array_map(
                fn ($model) => [
                    'name' => $model['name'],
                    'modified_at' => $model['modified_at'] ?? null,
                    'size' => $model['size'] ?? null,
                    'digest' => $model['digest'] ?? null,
                ],
                $data['models'] ?? []
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to list Ollama models: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Build the messages array for the chat API.
     *
     * @param  array<string, mixed>  $context
     * @return array<ChatMessage>
     */
    protected function buildMessages(Agent $agent, array $context): array
    {
        $messages = [];

        // System message with agent instructions
        $systemPrompt = $this->buildSystemPrompt($agent, $context);
        if (! empty($systemPrompt)) {
            $messages[] = ChatMessage::system($systemPrompt);
        }

        // Add conversation history if provided
        if (! empty($context['messages'])) {
            foreach ($context['messages'] as $message) {
                $messages[] = ChatMessage::fromArray($message);
            }
        }

        // Add the current user input
        if (! empty($context['input'])) {
            $images = $context['images'] ?? null;
            $messages[] = ChatMessage::user($context['input'], $images);
        }

        return $messages;
    }

    /**
     * Build the system prompt from agent configuration.
     *
     * Uses pre-assembled prompt from context if available (from PromptAssembler),
     * otherwise falls back to legacy behavior for backward compatibility.
     *
     * @param  array<string, mixed>  $context
     */
    protected function buildSystemPrompt(Agent $agent, array $context = []): string
    {
        $parts = [];

        // Use pre-assembled system prompt if provided
        if (! empty($context['system_prompt'])) {
            $parts[] = $context['system_prompt'];
        } else {
            // Legacy fallback: build from agent fields directly
            if (! empty($agent->description)) {
                $parts[] = $agent->description;
            }

            if (! empty($agent->code)) {
                $parts[] = $agent->code;
            }
        }

        // Add tool descriptions if the agent has tools
        $tools = $agent->tools;
        if ($tools->isNotEmpty()) {
            $toolDescriptions = $tools->map(function (Tool $tool) {
                return "- {$tool->name}: {$tool->type} tool";
            })->join("\n");

            $parts[] = "Available tools:\n{$toolDescriptions}";
        }

        // Add turn information
        $requestTurn = $context['request_turn'] ?? null;
        $maxTurns = $context['max_turns'] ?? null;
        if ($requestTurn !== null && $maxTurns !== null) {
            $parts[] = "Turn: {$requestTurn}/{$maxTurns}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build tools array in Ollama format from agent's tools.
     *
     * @return array<array<string, mixed>>
     */
    protected function buildTools(Agent $agent): array
    {
        $tools = $agent->tools;

        if ($tools->isEmpty()) {
            return [];
        }

        return $tools->map(function (Tool $tool) {
            return $this->convertToolToOllamaFormat($tool);
        })->filter()->values()->all();
    }

    /**
     * Convert an array of tool schemas to Ollama format.
     *
     * @param  array<array<string, mixed>>  $tools
     * @return array<array<string, mixed>>
     */
    protected function convertToolsToOllamaFormat(array $tools): array
    {
        return array_map(function (array $tool) {
            // Skip if already in Ollama format
            if (isset($tool['type']) && $tool['type'] === 'function') {
                return $tool;
            }

            $parameters = $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
            ];

            // Ensure properties is an object, not an empty array
            // (empty array [] becomes [] in JSON, but Ollama expects {})
            if (isset($parameters['properties']) && is_array($parameters['properties']) && empty($parameters['properties'])) {
                $parameters['properties'] = new \stdClass;
            }

            return [
                'type' => 'function',
                'function' => [
                    'name' => $this->sanitizeToolName($tool['name']),
                    'description' => $tool['description'] ?? '',
                    'parameters' => $parameters,
                ],
            ];
        }, $tools);
    }

    /**
     * Convert a Tool model to Ollama tool format.
     *
     * @return array<string, mixed>|null
     */
    protected function convertToolToOllamaFormat(Tool $tool): ?array
    {
        $config = $tool->config;

        // Build parameters schema based on tool config
        $parameters = $this->buildToolParameters($tool);

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->sanitizeToolName($tool->name),
                'description' => $config['description'] ?? "Execute {$tool->name} ({$tool->type} tool)",
                'parameters' => $parameters,
            ],
        ];
    }

    /**
     * Build parameters schema for a tool.
     *
     * @return array<string, mixed>
     */
    protected function buildToolParameters(Tool $tool): array
    {
        $config = $tool->config;

        // If parameters are explicitly defined in config, use them
        if (isset($config['parameters'])) {
            return $config['parameters'];
        }

        // Build default parameters based on tool type
        return match ($tool->type) {
            'api' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Query parameters or request body',
                    ],
                ],
                'required' => [],
            ],
            'function' => [
                'type' => 'object',
                'properties' => [
                    'input' => [
                        'type' => 'string',
                        'description' => 'Input for the function',
                    ],
                ],
                'required' => ['input'],
            ],
            'command' => [
                'type' => 'object',
                'properties' => [
                    'args' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                        'description' => 'Command arguments',
                    ],
                ],
                'required' => [],
            ],
            default => [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
            ],
        };
    }

    /**
     * Sanitize tool name for Ollama (must match pattern ^[a-zA-Z0-9_-]+$).
     */
    protected function sanitizeToolName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Parse the response from Ollama chat API.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseResponse(array $data): AIResponse
    {
        $message = $data['message'] ?? [];
        $content = $message['content'] ?? '';
        $thinking = $message['thinking'] ?? null;
        $toolCalls = $message['tool_calls'] ?? [];

        return $this->buildAIResponse($content, $data, $toolCalls, $thinking);
    }

    /**
     * Build an AIResponse from parsed data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<array<string, mixed>>  $toolCallsData
     */
    protected function buildAIResponse(string $content, array $data, array $toolCallsData, ?string $thinking = null): AIResponse
    {
        $toolCalls = array_map(
            fn ($tc) => $this->parseToolCall($tc),
            $toolCallsData
        );

        Log::info('Building AI response', [
            'content_length' => strlen($content),
            'raw_tool_calls_count' => count($toolCallsData),
            'raw_tool_calls' => $toolCallsData,
            'parsed_tool_calls' => array_map(fn ($tc) => $tc->toArray(), $toolCalls),
        ]);

        // Determine finish reason
        $finishReason = 'stop';
        if (! empty($toolCalls)) {
            $finishReason = 'tool_calls';
        } elseif (! ($data['done'] ?? true)) {
            $finishReason = 'length';
        }

        return new AIResponse(
            content: $content,
            model: $data['model'] ?? $this->model,
            tokensUsed: ($data['eval_count'] ?? 0) + ($data['prompt_eval_count'] ?? 0),
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            metadata: [
                'total_duration' => $data['total_duration'] ?? null,
                'load_duration' => $data['load_duration'] ?? null,
                'prompt_eval_count' => $data['prompt_eval_count'] ?? 0,
                'eval_count' => $data['eval_count'] ?? 0,
            ],
            thinking: $thinking
        );
    }

    /**
     * Read a line from the stream.
     *
     * @param  \Psr\Http\Message\StreamInterface  $stream
     */
    protected function readLine($stream): string
    {
        $line = '';

        while (! $stream->eof()) {
            $char = $stream->read(1);

            if ($char === "\n") {
                break;
            }

            $line .= $char;
        }

        return trim($line);
    }

    public function disconnect(): void
    {
        // Dereference old client to allow garbage collection of cURL handles
        unset($this->client);

        // Create fresh client with Connection: close to prevent HTTP keep-alive issues
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Connection' => 'close',
            ],
        ]);
    }

    /**
     * Format a ChatMessage for Ollama API.
     *
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message): array
    {
        $formatted = [
            'role' => $message->role,
            'content' => $message->content,
        ];

        if ($message->images !== null) {
            $formatted['images'] = $message->images;
        }

        if ($message->toolCalls !== null) {
            $formatted['tool_calls'] = $message->toolCalls;
        }

        if ($message->toolCallId !== null) {
            $formatted['tool_call_id'] = $message->toolCallId;
        }

        if ($message->thinking !== null) {
            $formatted['thinking'] = $message->thinking;
        }

        return $formatted;
    }

    /**
     * Parse a tool call from Ollama response format.
     *
     * @param  array<string, mixed>  $data
     */
    public function parseToolCall(array $data): ToolCall
    {
        return new ToolCall(
            id: $data['id'] ?? uniqid('call_'),
            name: $data['function']['name'] ?? '',
            arguments: $data['function']['arguments'] ?? []
        );
    }

    public function supportsModelManagement(): bool
    {
        return true;
    }

    public function pullModel(string $modelName, callable $onProgress): void
    {
        try {
            $response = $this->client->post('/api/pull', [
                'json' => ['name' => $modelName],
                'stream' => true,
                'timeout' => 0, // No timeout for large downloads
            ]);

            $body = $response->getBody();

            try {
                while (! $body->eof()) {
                    $line = $this->readLine($body);

                    if (empty($line)) {
                        continue;
                    }

                    $data = json_decode($line, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        continue;
                    }

                    $progress = ModelPullProgress::fromOllamaResponse($data);
                    $onProgress($progress);

                    if ($progress->isFailed()) {
                        throw new RuntimeException("Model pull failed: {$progress->error}");
                    }
                }
            } finally {
                $body->close();
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to pull model: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function deleteModel(string $modelName): void
    {
        try {
            $this->client->delete('/api/delete', [
                'json' => ['name' => $modelName],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to delete model: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function showModel(string $modelName): AIModel
    {
        try {
            $response = $this->client->post('/api/show', [
                'json' => ['name' => $modelName],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AIModel::fromOllamaShow($data);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to get model info: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Count the number of tokens in a text string using Ollama's tokenize API.
     *
     * Results are cached for 24 hours to avoid repeated API calls for the same text.
     */
    public function countTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        $cacheKey = 'tokens:'.hash('xxh3', $this->model.':'.$text);

        return Cache::remember($cacheKey, 86400, function () use ($text) {
            try {
                $response = $this->client->post('/api/tokenize', [
                    'json' => [
                        'model' => $this->model,
                        'prompt' => $text,
                    ],
                    'timeout' => 30,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);

                return count($data['tokens'] ?? []);
            } catch (GuzzleException $e) {
                Log::warning("Failed to tokenize text: {$e->getMessage()}");

                // Fallback: rough estimate of ~4 characters per token
                return (int) ceil(mb_strlen($text) / 4);
            }
        });
    }

    /**
     * Get the context limit (max tokens) for the current model.
     */
    public function getContextLimit(): int
    {
        return $this->normalizedConfig?->contextLength ?? 4096;
    }
}
