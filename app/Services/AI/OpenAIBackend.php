<?php

namespace App\Services\AI;

use App\Contracts\AIBackendInterface;
use App\DTOs\AIModel;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Models\Agent;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class OpenAIBackend implements AIBackendInterface
{
    protected Client $client;

    protected string $apiKey;

    protected string $model;

    protected int $timeout;

    protected int $maxTokens;

    protected ?NormalizedModelConfig $normalizedConfig = null;

    protected const BASE_URL = 'https://api.openai.com/v1/';

    public function __construct(array $config)
    {
        if (! $this->validateConfig($config)) {
            throw new InvalidArgumentException('Invalid OpenAI configuration: api_key is required');
        }

        $this->apiKey = $config['api_key'];
        $this->model = $config['model'] ?? 'gpt-4o';
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
                'Authorization' => "Bearer {$this->apiKey}",
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

            Log::info('OpenAI request payload', [
                'model' => $this->model,
                'tools_count' => count($payload['tools'] ?? []),
                'message_count' => count($payload['messages']),
            ]);

            $response = $this->client->post('chat/completions', [
                'json' => $payload,
                'timeout' => $this->timeout,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return $this->parseResponse($data);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "OpenAI API request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        try {
            $payload = $this->buildPayload($agent, $context, true);

            Log::info('OpenAI streaming request payload', [
                'model' => $this->model,
                'tools_count' => count($payload['tools'] ?? []),
                'message_count' => count($payload['messages']),
            ]);

            $response = $this->client->post('chat/completions', [
                'json' => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();
            $fullContent = '';
            $toolCalls = [];
            $lastData = [];
            $currentToolCalls = []; // Track partial tool calls by index

            try {
                $buffer = '';

                while (! $body->eof()) {
                    $chunk = $body->read(1024);
                    $buffer .= $chunk;

                    // Parse SSE data lines from buffer
                    while (($lineEnd = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $lineEnd);
                        $buffer = substr($buffer, $lineEnd + 1);

                        $line = trim($line);

                        if (empty($line)) {
                            continue;
                        }

                        if ($line === 'data: [DONE]') {
                            break 2;
                        }

                        if (! str_starts_with($line, 'data: ')) {
                            continue;
                        }

                        $jsonData = substr($line, 6);
                        $data = json_decode($jsonData, true);

                        if (json_last_error() !== JSON_ERROR_NONE) {
                            continue;
                        }

                        $lastData = $data;
                        $choices = $data['choices'] ?? [];

                        foreach ($choices as $choice) {
                            $delta = $choice['delta'] ?? [];

                            // Handle content delta
                            if (isset($delta['content']) && $delta['content'] !== '') {
                                $content = $delta['content'];
                                $fullContent .= $content;
                                $callback($content, 'content');
                            }

                            // Handle tool calls delta
                            if (isset($delta['tool_calls'])) {
                                foreach ($delta['tool_calls'] as $toolCallDelta) {
                                    $index = $toolCallDelta['index'] ?? 0;

                                    // Initialize tool call if this is the first chunk for this index
                                    if (! isset($currentToolCalls[$index])) {
                                        $currentToolCalls[$index] = [
                                            'id' => $toolCallDelta['id'] ?? uniqid('call_'),
                                            'type' => $toolCallDelta['type'] ?? 'function',
                                            'function' => [
                                                'name' => '',
                                                'arguments' => '',
                                            ],
                                        ];
                                    }

                                    // Update tool call data
                                    if (isset($toolCallDelta['id'])) {
                                        $currentToolCalls[$index]['id'] = $toolCallDelta['id'];
                                    }

                                    if (isset($toolCallDelta['function'])) {
                                        if (isset($toolCallDelta['function']['name'])) {
                                            $currentToolCalls[$index]['function']['name'] = $toolCallDelta['function']['name'];
                                        }
                                        if (isset($toolCallDelta['function']['arguments'])) {
                                            $currentToolCalls[$index]['function']['arguments'] .= $toolCallDelta['function']['arguments'];
                                        }
                                    }
                                }
                            }

                            // Check finish reason
                            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                                $lastData['finish_reason'] = $choice['finish_reason'];
                            }
                        }
                    }
                }

                // Convert accumulated tool calls
                foreach ($currentToolCalls as $tc) {
                    $toolCalls[] = $tc;
                }

                return $this->buildAIResponse($fullContent, $lastData, $toolCalls);
            } finally {
                $body->close();
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "OpenAI streaming request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Build the request payload for the OpenAI API.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildPayload(Agent $agent, array $context, bool $stream): array
    {
        $messages = $this->buildMessages($agent, $context);
        $tools = $context['tools'] ?? [];
        $tools = $this->convertToolsToOpenAIFormat($tools);

        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'stream' => $stream,
        ];

        // Use max_completion_tokens for newer models, max_tokens for older ones
        if ($this->isNewModel()) {
            $payload['max_completion_tokens'] = $this->maxTokens;
        } else {
            $payload['max_tokens'] = $this->maxTokens;
        }

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        // Add optional parameters from normalized config
        if ($this->normalizedConfig) {
            $params = $this->normalizedConfig->toOpenAIParams();
            // max_tokens is already set
            unset($params['max_tokens']);
            $payload = array_merge($payload, $params);
        }

        return $payload;
    }

    /**
     * Check if this is a newer model that uses max_completion_tokens.
     */
    protected function isNewModel(): bool
    {
        $newModels = ['gpt-4o', 'gpt-4-turbo', 'o1', 'o3', 'gpt-4.1', 'gpt-5'];

        foreach ($newModels as $prefix) {
            if (str_starts_with($this->model, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build the messages array for the OpenAI API.
     *
     * @param  array<string, mixed>  $context
     * @return array<array<string, mixed>>
     */
    protected function buildMessages(Agent $agent, array $context): array
    {
        $messages = [];

        // Add system prompt
        $systemPrompt = $this->buildSystemPrompt($agent, $context);
        if (! empty($systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        // Add conversation history if provided
        if (! empty($context['messages'])) {
            foreach ($context['messages'] as $message) {
                $chatMessage = ChatMessage::fromArray($message);
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
     * Build the system prompt from agent configuration.
     *
     * @param  array<string, mixed>  $context
     */
    protected function buildSystemPrompt(Agent $agent, array $context): string
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

        // Add turn information
        $requestTurn = $context['request_turn'] ?? null;
        $maxTurns = $context['max_turns'] ?? null;
        if ($requestTurn !== null && $maxTurns !== null) {
            $parts[] = "Turn: {$requestTurn}/{$maxTurns}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Convert tools to OpenAI format.
     *
     * @param  array<array<string, mixed>>  $tools
     * @return array<array<string, mixed>>
     */
    protected function convertToolsToOpenAIFormat(array $tools): array
    {
        return array_map(function (array $tool) {
            // If already in OpenAI format (has type: function)
            if (isset($tool['type']) && $tool['type'] === 'function') {
                return $tool;
            }

            $parameters = $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
            ];

            // Ensure properties is an object, not an empty array
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
     * Sanitize tool name (must match pattern ^[a-zA-Z0-9_-]+$).
     */
    protected function sanitizeToolName(string $name): string
    {
        return preg_replace('/[^a-zA-Z0-9_-]/', '_', $name);
    }

    /**
     * Parse the response from OpenAI API.
     *
     * @param  array<string, mixed>  $data
     */
    protected function parseResponse(array $data): AIResponse
    {
        $choices = $data['choices'] ?? [];
        $choice = $choices[0] ?? [];
        $message = $choice['message'] ?? [];

        $content = $message['content'] ?? '';
        $toolCalls = $message['tool_calls'] ?? [];

        return $this->buildAIResponse($content, $data, $toolCalls);
    }

    /**
     * Build an AIResponse from parsed data.
     *
     * @param  array<string, mixed>  $data
     * @param  array<array<string, mixed>>  $toolCallsData
     */
    protected function buildAIResponse(string $content, array $data, array $toolCallsData): AIResponse
    {
        $toolCalls = array_map(
            fn ($tc) => $this->parseToolCall($tc),
            $toolCallsData
        );

        Log::info('Building OpenAI AI response', [
            'content_length' => strlen($content),
            'tool_calls_count' => count($toolCalls),
        ]);

        // Determine finish reason
        $choices = $data['choices'] ?? [];
        $finishReason = $choices[0]['finish_reason'] ?? $data['finish_reason'] ?? 'stop';

        // Map OpenAI finish_reason to our format
        $finishReason = match ($finishReason) {
            'stop' => 'stop',
            'length' => 'length',
            'tool_calls' => 'tool_calls',
            'content_filter' => 'stop',
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
            tokensUsed: $usage['total_tokens'] ?? (($usage['prompt_tokens'] ?? 0) + ($usage['completion_tokens'] ?? 0)),
            finishReason: $finishReason,
            toolCalls: $toolCalls,
            metadata: [
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens' => $usage['total_tokens'] ?? 0,
                'completion_id' => $data['id'] ?? null,
            ],
            thinking: null
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
            'embeddings' => true,
        ];
    }

    /**
     * Format a ChatMessage for OpenAI API.
     *
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message): array
    {
        $role = $message->role;

        // Handle tool messages
        if ($role === ChatMessage::ROLE_TOOL) {
            return [
                'role' => 'tool',
                'tool_call_id' => $message->toolCallId,
                'content' => $message->content,
            ];
        }

        $formatted = [
            'role' => $role,
        ];

        // Build content (can be string or array)
        $content = [];

        // Add images if present
        if ($message->images !== null && ! empty($message->images)) {
            foreach ($message->images as $image) {
                // Determine URL format
                if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image,
                        ],
                    ];
                } else {
                    // Assume base64, prepend data URI if not present
                    $url = $image;
                    if (! str_starts_with($image, 'data:')) {
                        $url = 'data:image/jpeg;base64,'.$image;
                    }
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $url,
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

        // If only text content and no images, use string shorthand
        if (count($content) === 1 && $content[0]['type'] === 'text') {
            $formatted['content'] = $content[0]['text'];
        } elseif (! empty($content)) {
            $formatted['content'] = $content;
        } else {
            $formatted['content'] = $message->content;
        }

        // Add tool calls for assistant messages
        if ($role === 'assistant' && $message->toolCalls !== null && ! empty($message->toolCalls)) {
            $formatted['tool_calls'] = array_map(function ($tc) {
                return [
                    'id' => $tc['id'] ?? uniqid('call_'),
                    'type' => 'function',
                    'function' => [
                        'name' => $tc['function']['name'] ?? $tc['name'] ?? '',
                        'arguments' => is_string($tc['function']['arguments'] ?? $tc['arguments'] ?? null)
                            ? ($tc['function']['arguments'] ?? $tc['arguments'])
                            : json_encode($tc['function']['arguments'] ?? $tc['input'] ?? $tc['arguments'] ?? []),
                    ],
                ];
            }, $message->toolCalls);
        }

        return $formatted;
    }

    /**
     * Parse a tool call from OpenAI response format.
     *
     * @param  array<string, mixed>  $data
     */
    public function parseToolCall(array $data): ToolCall
    {
        $function = $data['function'] ?? [];
        $arguments = $function['arguments'] ?? '{}';

        // Parse JSON string arguments
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

    public function listModels(bool $detailed = false): array
    {
        // Known model metadata for enrichment
        $knownModels = [
            'gpt-4o' => [
                'description' => 'Latest multimodal model',
                'family' => 'gpt-4o',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 128000,
            ],
            'gpt-4o-mini' => [
                'description' => 'Smaller, faster GPT-4o variant',
                'family' => 'gpt-4o',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 128000,
            ],
            'gpt-4-turbo' => [
                'description' => 'Faster GPT-4 variant',
                'family' => 'gpt-4',
                'capabilities' => ['completion', 'vision', 'tool_use'],
                'context_length' => 128000,
            ],
            'gpt-4' => [
                'description' => 'Standard GPT-4',
                'family' => 'gpt-4',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 8192,
            ],
            'gpt-3.5-turbo' => [
                'description' => 'Fast, economical',
                'family' => 'gpt-3.5',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 16385,
            ],
            'o1' => [
                'description' => 'Advanced reasoning model',
                'family' => 'o1',
                'capabilities' => ['completion'],
                'context_length' => 200000,
            ],
            'o1-mini' => [
                'description' => 'Smaller reasoning model',
                'family' => 'o1',
                'capabilities' => ['completion'],
                'context_length' => 128000,
            ],
        ];

        try {
            $response = $this->client->get('models');
            $data = json_decode($response->getBody()->getContents(), true);
            $apiModels = $data['data'] ?? [];

            return array_map(function ($model) use ($detailed, $knownModels) {
                $name = $model['id'];
                $known = $knownModels[$name] ?? null;

                $result = [
                    'name' => $name,
                    'owned_by' => $model['owned_by'] ?? null,
                    'created' => $model['created'] ?? null,
                ];

                if ($detailed && $known) {
                    $result = array_merge($result, [
                        'description' => $known['description'],
                        'family' => $known['family'],
                        'capabilities' => $known['capabilities'],
                        'context_length' => $known['context_length'],
                    ]);
                } elseif ($detailed) {
                    // Unknown model, provide minimal detailed info
                    $result['capabilities'] = ['completion'];
                    $result['context_length'] = null;
                }

                return $result;
            }, $apiModels);
        } catch (GuzzleException $e) {
            // Return known models as fallback
            if (! $detailed) {
                return [
                    ['name' => 'gpt-4o', 'description' => 'Latest multimodal, 128K context'],
                    ['name' => 'gpt-4-turbo', 'description' => 'Faster GPT-4'],
                    ['name' => 'gpt-4', 'description' => 'Standard GPT-4'],
                    ['name' => 'gpt-3.5-turbo', 'description' => 'Fast, economical'],
                ];
            }

            // Return detailed fallback
            return array_map(function ($name) use ($knownModels) {
                $known = $knownModels[$name];

                return [
                    'name' => $name,
                    'description' => $known['description'],
                    'family' => $known['family'],
                    'capabilities' => $known['capabilities'],
                    'context_length' => $known['context_length'],
                ];
            }, ['gpt-4o', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo']);
        }
    }

    public function pullModel(string $modelName, callable $onProgress): void
    {
        throw new RuntimeException('Model management is not supported for OpenAI API');
    }

    public function deleteModel(string $modelName): void
    {
        throw new RuntimeException('Model management is not supported for OpenAI API');
    }

    public function showModel(string $modelName): AIModel
    {
        throw new RuntimeException('Model management is not supported for OpenAI API');
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
        return $this->normalizedConfig?->contextLength ?? 128000;
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
                'Authorization' => "Bearer {$this->apiKey}",
                'Connection' => 'close',
            ],
        ]);
    }

    /**
     * Generate embeddings for the given texts.
     *
     * @param  array<string>  $texts  Array of texts to embed
     * @param  string|null  $model  Optional model override (default: text-embedding-3-small)
     * @return array<array<float>> Array of embedding vectors
     *
     * @throws RuntimeException If embedding generation fails
     */
    public function generateEmbeddings(array $texts, ?string $model = null): array
    {
        $embeddingModel = $model ?? 'text-embedding-3-small';

        try {
            $response = $this->client->post('embeddings', [
                'json' => [
                    'model' => $embeddingModel,
                    'input' => $texts,
                ],
                'timeout' => 60,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (! isset($data['data']) || ! \is_array($data['data'])) {
                throw new RuntimeException('Invalid embedding response from OpenAI');
            }

            // Sort by index to ensure correct order
            $sorted = collect($data['data'])->sortBy('index')->values();

            return $sorted->map(fn ($item) => $item['embedding'])->toArray();
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to generate embeddings: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the embedding dimensions for a model.
     *
     * @param  string|null  $model  Optional model name
     * @return int The number of dimensions in the embedding vector
     */
    public function getEmbeddingDimensions(?string $model = null): int
    {
        $model = $model ?? 'text-embedding-3-small';

        return match ($model) {
            'text-embedding-3-small' => 1536,
            'text-embedding-3-large' => 3072,
            'text-embedding-ada-002' => 1536,
            default => 1536,
        };
    }
}
