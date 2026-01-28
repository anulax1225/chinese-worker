<?php

namespace App\Services\AI;

use App\Contracts\AIBackendInterface;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\ToolCall;
use App\Models\Agent;
use App\Models\Tool;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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

    public function execute(Agent $agent, array $context): AIResponse
    {
        try {
            $messages = $this->buildMessages($agent, $context);
            // Use tools from context if provided (from AgentLoopService), otherwise build from agent
            $tools = $context['tools'] ?? $this->buildTools($agent);

            $payload = [
                'model' => $this->model,
                'messages' => array_map(fn (ChatMessage $m) => $m->toOllama(), $messages),
                'stream' => false,
                'options' => $this->options,
            ];

            if (! empty($tools)) {
                $payload['tools'] = $tools;
            }
            Log::info('Ollama request payload'."\n".json_encode($payload, JSON_PRETTY_PRINT));
            $response = $this->client->post('/api/chat', [
                'json' => $payload,
                'timeout' => $this->timeout,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            Log::info('Ollama response data\n'.json_encode($data, JSON_PRETTY_PRINT));

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
            // Use tools from context if provided (from AgentLoopService), otherwise build from agent
            $tools = $context['tools'] ?? $this->buildTools($agent);

            $payload = [
                'model' => $this->model,
                'messages' => array_map(fn (ChatMessage $m) => $m->toOllama(), $messages),
                'stream' => true,
                'options' => $this->options,
            ];

            if (! empty($tools)) {
                $payload['tools'] = $tools;
            }

            $response = $this->client->post('/api/chat', [
                'json' => $payload,
                'stream' => true,
            ]);

            $body = $response->getBody();
            $fullContent = '';
            $lastData = [];
            $toolCalls = [];

            while (! $body->eof()) {
                $line = $this->readLine($body);

                if (empty($line)) {
                    continue;
                }

                $data = json_decode($line, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                // Handle content streaming
                if (isset($data['message']['content'])) {
                    $content = $data['message']['content'];
                    $fullContent .= $content;
                    $callback($content);
                }

                // Collect tool calls
                if (isset($data['message']['tool_calls'])) {
                    $toolCalls = array_merge($toolCalls, $data['message']['tool_calls']);
                }

                if (! empty($data['done'])) {
                    $lastData = $data;
                    break;
                }
            }

            return $this->buildAIResponse($fullContent, $lastData, $toolCalls);
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
        $systemPrompt = $this->buildSystemPrompt($agent);
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
     */
    protected function buildSystemPrompt(Agent $agent): string
    {
        $parts = [];

        if (! empty($agent->description)) {
            $parts[] = $agent->description;
        }

        if (! empty($agent->code)) {
            $parts[] = $agent->code;
        }

        // Add tool descriptions if the agent has tools
        $tools = $agent->tools;
        if ($tools->isNotEmpty()) {
            $toolDescriptions = $tools->map(function (Tool $tool) {
                return "- {$tool->name}: {$tool->type} tool";
            })->join("\n");

            $parts[] = "Available tools:\n{$toolDescriptions}";
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
            fn ($tc) => ToolCall::fromOllama($tc),
            $toolCallsData
        );

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
            ]
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
}
