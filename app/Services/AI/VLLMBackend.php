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
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;

class VLLMBackend implements AIBackendInterface
{
    protected Client $client;

    protected string $baseUrl;

    protected string $model;

    protected int $timeout;

    protected int $maxTokens;

    protected ?string $apiKey;

    protected ?NormalizedModelConfig $normalizedConfig = null;

    public function __construct(array $config)
    {
        if (! $this->validateConfig($config)) {
            throw new InvalidArgumentException('Invalid vLLM configuration: base_url is required and must be a valid URL');
        }

        $this->baseUrl = rtrim($config['base_url'], '/');
        $this->model = $config['model'] ?? 'meta-llama/Llama-3.1-8B-Instruct';
        $this->timeout = $config['timeout'] ?? 120;
        $this->maxTokens = $config['max_tokens'] ?? 4096;
        $this->apiKey = $config['api_key'] ?? null;

        $this->client = $this->createClient();
    }

    protected function createClient(): Client
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (! empty($this->apiKey)) {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        return new Client([
            'base_uri' => $this->baseUrl.'/',
            'timeout' => $this->timeout,
            'headers' => $headers,
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

            Log::info('vLLM request payload', [
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
            $this->handleApiError($e);
        }
    }

    public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
    {
        try {
            $payload = $this->buildPayload($agent, $context, true);

            Log::info('vLLM streaming request payload', [
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
            $this->handleApiError($e);
        }
    }

    /**
     * Check if the vLLM server is healthy and ready to accept requests.
     */
    public function isHealthy(): bool
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 5]);

            // First check manager status
            $statusResponse = $client->get("{$managerUrl}/api/status");
            $status = json_decode($statusResponse->getBody()->getContents(), true);

            // If manager reports vLLM as not ready, return false
            $vllmStatus = $status['vllm']['status'] ?? 'unknown';
            if ($vllmStatus !== 'ready') {
                return false;
            }

            // Also verify the health endpoint
            $healthResponse = $client->get("{$managerUrl}/health");

            return $healthResponse->getStatusCode() === 200;
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Handle API errors with self-hosted specific messages.
     */
    protected function handleApiError(GuzzleException $e): never
    {
        $statusCode = $e->getCode();
        $errorMessage = $e->getMessage();

        if (method_exists($e, 'getResponse') && $e->getResponse()) {
            $body = json_decode($e->getResponse()->getBody()->getContents(), true);
            $errorMessage = $body['error']['message'] ?? $body['error'] ?? $body['message'] ?? $errorMessage;
        }

        $msg = match ($statusCode) {
            0 => "vLLM: Cannot connect to server at {$this->baseUrl}. Is the inference server running?",
            401 => 'vLLM: Authentication failed. Check VLLM_API_KEY if your server requires authentication.',
            404 => 'vLLM: Model endpoint not found. The server may be serving a different model or not fully started.',
            422 => "vLLM: Invalid request — {$errorMessage}",
            429 => 'vLLM: Server overloaded. Try again later or scale your deployment.',
            500 => 'vLLM: Internal server error. Check inference server logs for details.',
            502, 503 => 'vLLM: Server unavailable. It may still be loading the model.',
            default => "vLLM server error: {$errorMessage}",
        };

        throw new RuntimeException($msg, $statusCode, $e);
    }

    /**
     * Build the request payload for the vLLM API.
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
            'model' => $this->model,
            'messages' => $messages,
            'stream' => $stream,
            'max_tokens' => $this->maxTokens,
        ];

        if (! empty($tools)) {
            $payload['tools'] = $tools;
        }

        if ($this->normalizedConfig) {
            $params = $this->normalizedConfig->toVLLMParams();
            unset($params['max_tokens']);
            $payload = array_merge($payload, $params);
        }

        return $payload;
    }

    /**
     * Build the messages array for the vLLM API.
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
     * Parse the response from vLLM API.
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

        Log::info('Building vLLM AI response', [
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
        if (! isset($config['base_url']) || empty($config['base_url'])) {
            return false;
        }

        return filter_var($config['base_url'], FILTER_VALIDATE_URL) !== false;
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
     * Format a ChatMessage for vLLM API.
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
     * Parse a tool call from vLLM response format.
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
        return true;
    }

    /**
     * Get the manager base URL (strips /v1 suffix if present).
     */
    protected function getManagerUrl(): string
    {
        return preg_replace('#/v1/?$#', '', $this->baseUrl);
    }

    /**
     * List downloaded models from the vLLM manager.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listModels(bool $detailed = false): array
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 10]);
            $response = $client->get("{$managerUrl}/api/tags");
            $data = json_decode($response->getBody()->getContents(), true);
            $models = $data['models'] ?? [];

            if (! $detailed) {
                return array_map(
                    fn ($m) => AIModel::fromVLLMTag($m)->toArray(),
                    $models
                );
            }

            // Fetch detailed info for each model via /api/show
            return array_map(function ($model) use ($managerUrl, $client) {
                try {
                    $showResponse = $client->post("{$managerUrl}/api/show", [
                        'json' => ['name' => $model['name']],
                    ]);
                    $showData = json_decode($showResponse->getBody()->getContents(), true);

                    return AIModel::fromVLLMShow($showData, $model['name'])->toArray();
                } catch (GuzzleException $e) {
                    Log::warning("Failed to fetch vLLM model details for {$model['name']}: {$e->getMessage()}");

                    return AIModel::fromVLLMTag($model)->toArray();
                }
            }, $models);
        } catch (GuzzleException $e) {
            Log::warning("Failed to list vLLM models from manager: {$e->getMessage()}");

            // Fallback to legacy /v1/models endpoint
            return $this->listModelsLegacy();
        }
    }

    /**
     * Fallback model listing via OpenAI-compatible /v1/models endpoint.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function listModelsLegacy(): array
    {
        try {
            $response = $this->client->get('models');
            $data = json_decode($response->getBody()->getContents(), true);
            $models = $data['data'] ?? [];

            return array_map(
                fn ($m) => AIModel::fromVLLM($m)->toArray(),
                $models
            );
        } catch (GuzzleException $e) {
            Log::warning("Failed to list vLLM models: {$e->getMessage()}");

            return [
                [
                    'name' => $this->model,
                    'description' => 'Configured model (server unreachable)',
                ],
            ];
        }
    }

    /**
     * Pull (download) a model from HuggingFace Hub.
     * Uses the manager's /api/pull endpoint with NDJSON streaming.
     */
    public function pullModel(string $modelName, callable $onProgress): void
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 0]); // No timeout for large downloads

            $response = $client->post("{$managerUrl}/api/pull", [
                'json' => ['name' => $modelName, 'stream' => true],
                'stream' => true,
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

                    $progress = ModelPullProgress::fromVLLMResponse($data);
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

    /**
     * Delete a model from the HuggingFace cache.
     */
    public function deleteModel(string $modelName): void
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 30]);

            $client->delete("{$managerUrl}/api/delete", [
                'json' => ['name' => $modelName],
            ]);
        } catch (GuzzleException $e) {
            $statusCode = $e->getCode();

            if ($statusCode === 409) {
                throw new RuntimeException(
                    "Cannot delete currently loaded model '{$modelName}'. Switch to another model first.",
                    409,
                    $e
                );
            }

            throw new RuntimeException(
                "Failed to delete model: {$e->getMessage()}",
                $statusCode,
                $e
            );
        }
    }

    /**
     * Get detailed model information.
     */
    public function showModel(string $modelName): AIModel
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 10]);

            $response = $client->post("{$managerUrl}/api/show", [
                'json' => ['name' => $modelName],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AIModel::fromVLLMShow($data, $modelName);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to get model info: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Pre-warm: explicitly load a model before the first inference request.
     *
     * This is optional — the manager auto-switches on model mismatch
     * (like Ollama). Use this from the UI when the user changes model
     * in agent config to avoid cold-start delay on next chat message.
     *
     * @return array<string, mixed>
     */
    public function switchModel(string $modelName): array
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 600]); // Model loading can take time

            $response = $client->post("{$managerUrl}/api/switch", [
                'json' => ['name' => $modelName],
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to switch model: {$e->getMessage()}",
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get manager and vLLM subprocess status.
     *
     * @return array<string, mixed>
     */
    public function getStatus(): array
    {
        try {
            $managerUrl = $this->getManagerUrl();
            $client = new Client(['timeout' => 5]);

            $response = $client->get("{$managerUrl}/api/status");

            return json_decode($response->getBody()->getContents(), true);
        } catch (GuzzleException $e) {
            return [
                'manager' => 'unreachable',
                'vllm' => ['status' => 'unknown'],
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Read a line from the stream (for NDJSON parsing).
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

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Connection' => 'close',
        ];

        if (! empty($this->apiKey)) {
            $headers['Authorization'] = "Bearer {$this->apiKey}";
        }

        $this->client = new Client([
            'base_uri' => $this->baseUrl.'/',
            'timeout' => $this->timeout,
            'headers' => $headers,
        ]);
    }
}
