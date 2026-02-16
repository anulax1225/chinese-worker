<?php

namespace App\Services\AI;

use App\Contracts\AIBackendInterface;
use App\DTOs\AIModel;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Models\Agent;
use App\Models\Tool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class AnthropicBackend implements AIBackendInterface
{
    protected Client $client;

    protected string $apiKey;

    protected string $model;

    protected int $timeout;

    protected int $maxTokens;

    protected ?NormalizedModelConfig $normalizedConfig = null;

    protected const BASE_URL = 'https://api.anthropic.com/v1/';

    protected const ANTHROPIC_VERSION = '2023-06-01';

    public function __construct(array $config)
    {
        if (! $this->validateConfig($config)) {
            throw new InvalidArgumentException('Invalid Anthropic configuration: api_key is required');
        }

        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? 'claude-sonnet-4-5-20250929';
        $this->timeout = $config['timeout'] ?? 120;
        $this->maxTokens = $config['max_tokens'] ?? 4096;

        $this->client = $this->createClient();
    }

    protected function createClient(): Client
    {
        return new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'x-api-key' => $this->apiKey,
            ],
        ]);
    }

    public function withConfig(NormalizedModelConfig $config): static
    {
        $clone = clone $this;
        $clone->normalizedConfig = $config;
        $clone->model = $config->model;
        $clone->timeout = $config->timeout;
        $clone->maxTokens = $config->maxTokens;
        $clone->client = $clone->createClient();

        return $clone;
    }

    public function execute(Agent $agent, array $context): AIResponse
    {
        try {
            $payload = $this->buildPayload($agent, $context, false);

            Log::info('Anthropic request payload', [
                'model' => $this->model,
                'tools_count' => count($payload['tools'] ?? []),
                'message_count' => count($payload['messages']),
            ]);

            $response = $this->client->post('messages', [
                'json' => $payload,
                'timeout' => $this->timeout,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (RequestException $e) {
            $errorMessage = "Anthropic API request failed: {$e->getMessage()}";
            if ($e->hasResponse()) {
                $body = $e->getResponse()->getBody()->getContents();
                Log::error('Anthropic API error response', ['body' => $body]);
                $errorMessage .= " - Response: {$body}";
            }
            throw new RuntimeException($errorMessage, $e->getCode(), $e);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Anthropic API request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        try {
            $payload = $this->buildPayload($agent, $context, true);

            Log::info('Anthropic streaming request payload', [
                'model' => $this->model,
                'tools_count' => count($payload['tools'] ?? []),
                'message_count' => count($payload['messages']),
                'payload' => $payload,
            ]);

            $response = $this->client->post('messages', [
                'json' => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();
            $fullContent = '';
            $fullThinking = '';
            $toolCalls = [];
            $messageData = [];
            $currentContentBlock = null;
            $currentToolCall = null;

            try {
                $buffer = '';

                while (! $body->eof()) {
                    $chunk = $body->read(1024);
                    $buffer .= $chunk;

                    // Parse SSE events from buffer
                    while (($eventEnd = strpos($buffer, "\n\n")) !== false) {
                        $eventData = substr($buffer, 0, $eventEnd);
                        $buffer = substr($buffer, $eventEnd + 2);

                        $event = $this->parseSSEEvent($eventData);
                        if ($event === null) {
                            continue;
                        }

                        switch ($event['type']) {
                            case 'message_start':
                                $messageData = $event['message'] ?? [];
                                break;

                            case 'content_block_start':
                                $currentContentBlock = $event['content_block'] ?? [];
                                if (($currentContentBlock['type'] ?? '') === 'tool_use') {
                                    $currentToolCall = [
                                        'id' => $currentContentBlock['id'] ?? uniqid('toolu_'),
                                        'name' => $currentContentBlock['name'] ?? '',
                                        'input' => '',
                                    ];
                                }
                                break;

                            case 'content_block_delta':
                                $delta = $event['delta'] ?? [];
                                $deltaType = $delta['type'] ?? '';

                                if ($deltaType === 'text_delta') {
                                    $text = $delta['text'] ?? '';
                                    $fullContent .= $text;
                                    $callback($text, 'content');
                                } elseif ($deltaType === 'thinking_delta') {
                                    $thinking = $delta['thinking'] ?? '';
                                    $fullThinking .= $thinking;
                                    $callback($thinking, 'thinking');
                                } elseif ($deltaType === 'input_json_delta') {
                                    if ($currentToolCall !== null) {
                                        $currentToolCall['input'] .= $delta['partial_json'] ?? '';
                                    }
                                }
                                break;

                            case 'content_block_stop':
                                if ($currentToolCall !== null) {
                                    // Parse the accumulated JSON input
                                    $input = json_decode($currentToolCall['input'], true) ?? [];
                                    $toolCalls[] = [
                                        'id' => $currentToolCall['id'],
                                        'name' => $currentToolCall['name'],
                                        'input' => $input,
                                    ];
                                    $currentToolCall = null;
                                }
                                $currentContentBlock = null;
                                break;

                            case 'message_delta':
                                $messageData = array_merge($messageData, $event['delta'] ?? []);
                                if (isset($event['usage'])) {
                                    $messageData['usage'] = array_merge(
                                        $messageData['usage'] ?? [],
                                        $event['usage']
                                    );
                                }
                                break;

                            case 'message_stop':
                                // Stream complete
                                break;
                        }
                    }
                }

                return $this->buildAIResponse($fullContent, $messageData, $toolCalls, $fullThinking ?: null);
            } finally {
                $body->close();
            }
        } catch (RequestException $e) {
            $errorMessage = "Anthropic streaming request failed: {$e->getMessage()}";
            if ($e->hasResponse()) {
                $body = $e->getResponse()->getBody()->getContents();
                Log::error('Anthropic streaming API error response', ['body' => $body]);
                $errorMessage .= " - Response: {$body}";
            }
            throw new RuntimeException($errorMessage, $e->getCode(), $e);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Anthropic streaming request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Parse an SSE event from raw event data.
     *
     * @return array<string, mixed>|null
     */
    protected function parseSSEEvent(string $eventData): ?array
    {
        $lines = explode("\n", $eventData);
        $eventType = null;
        $data = null;

        foreach ($lines as $line) {
            if (str_starts_with($line, 'event: ')) {
                $eventType = trim(substr($line, 7));
            } elseif (str_starts_with($line, 'data: ')) {
                $data = trim(substr($line, 6));
            }
        }

        if ($data === null) {
            return null;
        }

        $parsed = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        return $parsed;
    }

    /**
     * Build the request payload for the Anthropic API.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildPayload(Agent $agent, array $context, bool $stream): array
    {
        $messages = $this->buildMessages($agent, $context);
        $systemPrompt = $this->extractSystemPrompt($agent, $context);
        $tools = $context['tools'] ?? $this->buildTools($agent);
        $tools = $this->convertToolsToAnthropicFormat($tools);

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'messages' => $messages,
            'stream' => $stream,
        ];

        if (! empty($systemPrompt)) {
            $payload['system'] = $systemPrompt;
        }

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        // Add optional parameters from normalized config
        if ($this->normalizedConfig) {
            $params = $this->normalizedConfig->toAnthropicParams();
            // max_tokens is already set, remove from params
            unset($params['max_tokens']);
            $payload = array_merge($payload, $params);
        }

        return $payload;
    }

    /**
     * Build the messages array for the Anthropic API.
     *
     * @param  array<string, mixed>  $context
     * @return array<array<string, mixed>>
     */
    protected function buildMessages(Agent $agent, array $context): array
    {
        $messages = [];

        // Add conversation history if provided
        if (! empty($context['messages'])) {
            foreach ($context['messages'] as $message) {
                $chatMessage = ChatMessage::fromArray($message);
                // Skip system messages - they go in the system parameter
                if ($chatMessage->role === ChatMessage::ROLE_SYSTEM) {
                    continue;
                }
                $messages[] = $this->formatMessage($chatMessage);
            }
        }

        // Add the current user input
        if (! empty($context['input'])) {
            $images = $context['images'] ?? null;
            $messages[] = $this->formatMessage(ChatMessage::user($context['input'], $images));
        }

        return $messages;
    }

    /**
     * Extract system prompt from context.
     *
     * @param  array<string, mixed>  $context
     */
    protected function extractSystemPrompt(Agent $agent, array $context): string
    {
        $parts = [];

        // Use pre-assembled system prompt if provided
        if (! empty($context['system_prompt'])) {
            $parts[] = $context['system_prompt'];
        } else {
            // Legacy fallback
            if (! empty($agent->description)) {
                $parts[] = $agent->description;
            }
            if (! empty($agent->code)) {
                $parts[] = $agent->code;
            }
        }

        // Add tool descriptions
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
     * Build tools array from agent's tools.
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
            return [
                'name' => $this->sanitizeToolName($tool->name),
                'description' => $tool->config['description'] ?? "Execute {$tool->name} ({$tool->type} tool)",
                'parameters' => $this->buildToolParameters($tool),
            ];
        })->filter()->values()->all();
    }

    /**
     * Convert tools to Anthropic format.
     *
     * @param  array<array<string, mixed>>  $tools
     * @return array<array<string, mixed>>
     */
    protected function convertToolsToAnthropicFormat(array $tools): array
    {
        return array_map(function (array $tool) {
            // If already in Anthropic format (has input_schema)
            if (isset($tool['input_schema'])) {
                return $tool;
            }

            $inputSchema = $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
            ];

            // Ensure properties is an object, not an empty array
            if (isset($inputSchema['properties']) && is_array($inputSchema['properties']) && empty($inputSchema['properties'])) {
                $inputSchema['properties'] = new \stdClass;
            }

            return [
                'name' => $this->sanitizeToolName($tool['name']),
                'description' => $tool['description'] ?? '',
                'input_schema' => $inputSchema,
            ];
        }, $tools);
    }

    /**
     * Build parameters schema for a tool.
     *
     * @return array<string, mixed>
     */
    protected function buildToolParameters(Tool $tool): array
    {
        $config = $tool->config;

        if (isset($config['parameters'])) {
            return $config['parameters'];
        }

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
     * Sanitize tool name (must match pattern ^[a-zA-Z0-9_-]+$).
     */
    protected function sanitizeToolName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Parse the response from Anthropic API.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseResponse(array $data): AIResponse
    {
        $content = '';
        $thinking = null;
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            $type = $block['type'] ?? '';

            if ($type === 'text') {
                $content .= $block['text'] ?? '';
            } elseif ($type === 'thinking') {
                $thinking = ($thinking ?? '').($block['thinking'] ?? '');
            } elseif ($type === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'] ?? uniqid('toolu_'),
                    'name' => $block['name'] ?? '',
                    'input' => $block['input'] ?? [],
                ];
            }
        }

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

        Log::info('Building Anthropic AI response', [
            'content_length' => strlen($content),
            'tool_calls_count' => count($toolCalls),
            'stop_reason' => $data['stop_reason'] ?? null,
        ]);

        // Map Anthropic stop_reason to our finish_reason
        $stopReason = $data['stop_reason'] ?? 'end_turn';
        $finishReason = match ($stopReason) {
            'end_turn' => 'stop',
            'max_tokens' => 'length',
            'stop_sequence' => 'stop',
            'tool_use' => 'tool_calls',
            default => 'stop',
        };

        // Override if we have tool calls
        if (! empty($toolCalls)) {
            $finishReason = 'tool_calls';
        }

        $usage = $data['usage'] ?? [];

        return new AIResponse(
            content: $content,
            model: $data['model'] ?? $this->model,
            tokensUsed: ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            metadata: [
                'input_tokens' => $usage['input_tokens'] ?? 0,
                'output_tokens' => $usage['output_tokens'] ?? 0,
                'stop_reason' => $stopReason,
                'message_id' => $data['id'] ?? null,
            ],
            thinking: $thinking
        );
    }

    public function validateConfig(array $config): bool
    {
        return isset($config['api_key']) && ! empty($config['api_key']);
    }

    public function getCapabilities(): array
    {
        return [
            'streaming' => true,
            'function_calling' => true,
            'vision' => true,
            'embeddings' => false,
        ];
    }

    /**
     * Format a ChatMessage for Anthropic API.
     *
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message): array
    {
        $role = $message->role;

        // Anthropic only supports user and assistant roles in messages
        if ($role === ChatMessage::ROLE_SYSTEM) {
            // System messages should be handled separately via system parameter
            $role = 'user';
        } elseif ($role === ChatMessage::ROLE_TOOL) {
            // Tool results are sent as user messages with tool_result content
            return [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'tool_result',
                        'tool_use_id' => $message->toolCallId,
                        'content' => $message->content,
                    ],
                ],
            ];
        }

        // Build content
        $content = [];

        // Add images if present
        if ($message->images !== null && ! empty($message->images)) {
            foreach ($message->images as $image) {
                // Determine if it's a URL or base64
                if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                    $content[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'url',
                            'url' => $image,
                        ],
                    ];
                } else {
                    // Assume base64, detect media type
                    $mediaType = 'image/jpeg';
                    if (str_starts_with($image, 'data:')) {
                        if (preg_match('/^data:(image\/[^;]+);base64,/', $image, $matches)) {
                            $mediaType = $matches[1];
                            $image = preg_replace('/^data:image\/[^;]+;base64,/', '', $image);
                        }
                    }
                    $content[] = [
                        'type' => 'image',
                        'source' => [
                            'type' => 'base64',
                            'media_type' => $mediaType,
                            'data' => $image,
                        ],
                    ];
                }
            }
        }

        // Add text content
        if (! empty($message->content)) {
            $content[] = [
                'type' => 'text',
                'text' => $message->content,
            ];
        }

        // Add tool calls for assistant messages
        if ($role === 'assistant' && $message->toolCalls !== null) {
            foreach ($message->toolCalls as $toolCall) {
                $content[] = [
                    'type' => 'tool_use',
                    'id' => $toolCall['id'] ?? uniqid('toolu_'),
                    'name' => $toolCall['function']['name'] ?? $toolCall['name'] ?? '',
                    'input' => $toolCall['function']['arguments'] ?? $toolCall['input'] ?? $toolCall['arguments'] ?? [],
                ];
            }
        }

        // If only text content, can use string shorthand
        if (count($content) === 1 && $content[0]['type'] === 'text') {
            return [
                'role' => $role,
                'content' => $content[0]['text'],
            ];
        }

        return [
            'role' => $role,
            'content' => $content,
        ];
    }

    /**
     * Parse a tool call from Anthropic response format.
     *
     * @param  array<string, mixed>  $data
     */
    public function parseToolCall(array $data): ToolCall
    {
        return new ToolCall(
            id: $data['id'] ?? uniqid('toolu_'),
            name: $data['name'] ?? '',
            arguments: $data['input'] ?? []
        );
    }

    public function supportsModelManagement(): bool
    {
        return false;
    }

    public function listModels(bool $detailed = false): array
    {
        // Anthropic doesn't have a models endpoint - return known models with mock data
        $models = [
            [
                'name' => 'claude-opus-4-6',
                'description' => 'Most intelligent, latest Opus',
                'family' => 'claude-4',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-sonnet-4-5-20250929',
                'description' => 'Best balance of intelligence and speed',
                'family' => 'claude-4',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-haiku-4-5',
                'description' => 'Fastest and most compact',
                'family' => 'claude-4',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-sonnet-4-20250514',
                'description' => 'Claude 4 Sonnet',
                'family' => 'claude-4',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-3-7-sonnet-latest',
                'description' => 'Claude 3.7 Sonnet latest',
                'family' => 'claude-3.7',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-3-5-haiku-latest',
                'description' => 'Claude 3.5 Haiku latest',
                'family' => 'claude-3.5',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-3-opus-20240229',
                'description' => 'Claude 3 Opus',
                'family' => 'claude-3',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
            [
                'name' => 'claude-3-haiku-20240307',
                'description' => 'Claude 3 Haiku',
                'family' => 'claude-3',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 200000,
            ],
        ];

        if (! $detailed) {
            // Return minimal info for backward compatibility
            return array_map(fn ($m) => [
                'name' => $m['name'],
                'description' => $m['description'],
            ], $models);
        }

        return $models;
    }

    public function pullModel(string $modelName, callable $onProgress): void
    {
        throw new RuntimeException('Model management is not supported for Anthropic API');
    }

    public function deleteModel(string $modelName): void
    {
        throw new RuntimeException('Model management is not supported for Anthropic API');
    }

    public function showModel(string $modelName): AIModel
    {
        throw new RuntimeException('Model management is not supported for Anthropic API');
    }

    public function countTokens(string $text): int
    {
        if (empty($text)) {
            return 0;
        }

        // Rough estimate: ~4 characters per token
        return (int) ceil(mb_strlen($text) / 4);
    }

    public function getContextLimit(): int
    {
        return $this->normalizedConfig?->contextLength ?? 200000;
    }

    public function disconnect(): void
    {
        unset($this->client);

        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'anthropic-version' => self::ANTHROPIC_VERSION,
                'x-api-key' => $this->apiKey,
                'Connection' => 'close',
            ],
        ]);
    }

    /**
     * Generate embeddings for the given texts.
     *
     * Anthropic does not provide an embeddings API.
     *
     * @throws RuntimeException Always throws as embeddings are not supported
     */
    public function generateEmbeddings(array $_texts, ?string $_model = null): array
    {
        throw new RuntimeException('Embeddings are not supported by Anthropic API. Use OpenAI or Ollama backend for embeddings.');
    }

    /**
     * Get the embedding dimensions for a model.
     *
     * @throws RuntimeException Always throws as embeddings are not supported
     */
    public function getEmbeddingDimensions(?string $_model = null): int
    {
        throw new RuntimeException('Embeddings are not supported by Anthropic API.');
    }
}
