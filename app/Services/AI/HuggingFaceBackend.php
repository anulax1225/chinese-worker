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
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class HuggingFaceBackend implements AIBackendInterface
{
    protected Client $client;

    protected string $apiKey;

    protected string $baseUrl;

    protected string $model;

    protected int $timeout;

    protected int $maxTokens;

    protected ?string $provider;

    protected ?NormalizedModelConfig $normalizedConfig = null;

    protected const DEFAULT_BASE_URL = 'https://router.huggingface.co/v1';

    public function __construct(array $config)
    {
        if (! $this->validateConfig($config)) {
            throw new InvalidArgumentException('Invalid HuggingFace configuration: api_key is required and must start with "hf_"');
        }

        $this->apiKey = $config['api_key'];
        $this->baseUrl = rtrim($config['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->model = $config['model'] ?? 'meta-llama/Llama-3.1-8B-Instruct';
        $this->timeout = $config['timeout'] ?? 120;
        $this->maxTokens = $config['max_tokens'] ?? 4096;
        $this->provider = $config['provider'] ?? null;

        $this->client = $this->createClient();
    }

    protected function createClient(): Client
    {
        return new Client([
            'base_uri' => $this->baseUrl.'/',
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

            Log::info('HuggingFace request payload', [
                'model' => $this->getModelWithProvider(),
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
                "HuggingFace API request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        try {
            $payload = $this->buildPayload($agent, $context, true);

            Log::info('HuggingFace streaming request payload', [
                'model' => $this->getModelWithProvider(),
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
            $currentToolCalls = [];

            try {
                $buffer = '';

                while (! $body->eof()) {
                    $chunk = $body->read(1024);
                    $buffer .= $chunk;

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

                            if (isset($delta['content']) && $delta['content'] !== '') {
                                $content = $delta['content'];
                                $fullContent .= $content;
                                $callback($content, 'content');
                            }

                            if (isset($delta['tool_calls'])) {
                                foreach ($delta['tool_calls'] as $toolCallDelta) {
                                    $index = $toolCallDelta['index'] ?? 0;

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

                            if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                                $lastData['finish_reason'] = $choice['finish_reason'];
                            }
                        }
                    }
                }

                foreach ($currentToolCalls as $tc) {
                    $toolCalls[] = $tc;
                }

                return $this->buildAIResponse($fullContent, $lastData, $toolCalls);
            } finally {
                $body->close();
            }
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "HuggingFace streaming request failed: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get the model name with optional provider suffix.
     */
    protected function getModelWithProvider(): string
    {
        if ($this->provider !== null && $this->provider !== '') {
            return "{$this->model}:{$this->provider}";
        }

        return $this->model;
    }

    /**
     * Build the request payload for the HuggingFace API.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function buildPayload(Agent $agent, array $context, bool $stream): array
    {
        $messages = $this->buildMessages($agent, $context);
        $tools = $context['tools'] ?? $this->buildTools($agent);
        $tools = $this->convertToolsToOpenAIFormat($tools);

        $payload = [
            'model' => $this->getModelWithProvider(),
            'messages' => $messages,
            'stream' => $stream,
            'max_tokens' => $this->maxTokens,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        if ($this->normalizedConfig) {
            $params = $this->normalizedConfig->toHuggingFaceParams();
            unset($params['max_tokens']);
            $payload = array_merge($payload, $params);
        }

        return $payload;
    }

    /**
     * Build the messages array for the HuggingFace API.
     *
     * @param  array<string, mixed>  $context
     * @return array<array<string, mixed>>
     */
    protected function buildMessages(Agent $agent, array $context): array
    {
        $messages = [];

        $systemPrompt = $this->buildSystemPrompt($agent, $context);
        if (! empty($systemPrompt)) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt,
            ];
        }

        if (! empty($context['messages'])) {
            foreach ($context['messages'] as $message) {
                $chatMessage = ChatMessage::fromArray($message);
                $messages[] = $this->formatMessage($chatMessage);
            }
        }

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

        if (! empty($context['system_prompt'])) {
            $parts[] = $context['system_prompt'];
        } else {
            if (! empty($agent->description)) {
                $parts[] = $agent->description;
            }
            if (! empty($agent->code)) {
                $parts[] = $agent->code;
            }
        }

        $tools = $agent->tools;
        if ($tools->isNotEmpty()) {
            $toolDescriptions = $tools->map(function (Tool $tool) {
                return "- {$tool->name}: {$tool->type} tool";
            })->join("\n");
            $parts[] = "Available tools:\n{$toolDescriptions}";
        }

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
     * Convert tools to OpenAI format.
     *
     * @param  array<array<string, mixed>>  $tools
     * @return array<array<string, mixed>>
     */
    protected function convertToolsToOpenAIFormat(array $tools): array
    {
        return array_map(function (array $tool) {
            if (isset($tool['type']) && $tool['type'] === 'function') {
                return $tool;
            }

            $parameters = $tool['parameters'] ?? [
                'type' => 'object',
                'properties' => new \stdClass,
                'required' => [],
            ];

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
     * Parse the response from HuggingFace API.
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

        Log::info('Building HuggingFace AI response', [
            'content_length' => strlen($content),
            'tool_calls_count' => count($toolCalls),
        ]);

        $choices = $data['choices'] ?? [];
        $finishReason = $choices[0]['finish_reason'] ?? $data['finish_reason'] ?? 'stop';

        $finishReason = match ($finishReason) {
            'stop' => 'stop',
            'length' => 'length',
            'tool_calls' => 'tool_calls',
            'content_filter' => 'stop',
            default => 'stop',
        };

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
        if (! isset($config['api_key']) || empty($config['api_key'])) {
            return false;
        }

        if (! str_starts_with($config['api_key'], 'hf_')) {
            return false;
        }

        return true;
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
     * Format a ChatMessage for HuggingFace API.
     *
     * @return array<string, mixed>
     */
    public function formatMessage(ChatMessage $message): array
    {
        $role = $message->role;

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

        $content = [];

        if ($message->images !== null && ! empty($message->images)) {
            foreach ($message->images as $image) {
                if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://')) {
                    $content[] = [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $image,
                        ],
                    ];
                } else {
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

        if (! empty($message->content)) {
            $content[] = [
                'type' => 'text',
                'text' => $message->content,
            ];
        }

        if (count($content) === 1 && $content[0]['type'] === 'text') {
            $formatted['content'] = $content[0]['text'];
        } elseif (! empty($content)) {
            $formatted['content'] = $content;
        } else {
            $formatted['content'] = $message->content;
        }

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
     * Parse a tool call from HuggingFace response format.
     *
     * @param  array<string, mixed>  $data
     */
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

    public function listModels(bool $detailed = false): array
    {
        $knownModels = [
            'meta-llama/Llama-3.1-8B-Instruct' => [
                'description' => 'Llama 3.1 8B - Fast, efficient general-purpose model',
                'family' => 'llama',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 131072,
            ],
            'meta-llama/Llama-3.1-70B-Instruct' => [
                'description' => 'Llama 3.1 70B - High capability model',
                'family' => 'llama',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 131072,
            ],
            'Qwen/Qwen2.5-72B-Instruct' => [
                'description' => 'Qwen 2.5 72B - Strong multilingual model',
                'family' => 'qwen',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 131072,
            ],
            'Qwen/Qwen2.5-Coder-32B-Instruct' => [
                'description' => 'Qwen 2.5 Coder 32B - Code-specialized model',
                'family' => 'qwen',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 131072,
            ],
            'mistralai/Mistral-7B-Instruct-v0.3' => [
                'description' => 'Mistral 7B - Fast and efficient',
                'family' => 'mistral',
                'capabilities' => ['completion', 'tool_use'],
                'context_length' => 32768,
            ],
            'deepseek-ai/DeepSeek-R1' => [
                'description' => 'DeepSeek R1 - Reasoning-focused model',
                'family' => 'deepseek',
                'capabilities' => ['completion'],
                'context_length' => 65536,
            ],
            'google/gemma-2-2b-it' => [
                'description' => 'Gemma 2 2B - Tiny, fast model',
                'family' => 'gemma',
                'capabilities' => ['completion'],
                'context_length' => 8192,
            ],
        ];

        if (! $detailed) {
            return array_map(function ($name, $info) {
                return [
                    'name' => $name,
                    'description' => $info['description'],
                ];
            }, array_keys($knownModels), array_values($knownModels));
        }

        return array_map(function ($name) use ($knownModels) {
            $info = $knownModels[$name];

            return [
                'name' => $name,
                'description' => $info['description'],
                'family' => $info['family'],
                'capabilities' => $info['capabilities'],
                'context_length' => $info['context_length'],
            ];
        }, array_keys($knownModels));
    }

    public function pullModel(string $modelName, callable $onProgress): void
    {
        throw new RuntimeException('Model management is not supported for HuggingFace Inference Providers');
    }

    public function deleteModel(string $modelName): void
    {
        throw new RuntimeException('Model management is not supported for HuggingFace Inference Providers');
    }

    public function showModel(string $modelName): AIModel
    {
        throw new RuntimeException('Model management is not supported for HuggingFace Inference Providers');
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
        return $this->normalizedConfig?->contextLength ?? 131072;
    }

    public function disconnect(): void
    {
        unset($this->client);

        $this->client = new Client([
            'base_uri' => $this->baseUrl.'/',
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$this->apiKey}",
                'Connection' => 'close',
            ],
        ]);
    }
}
