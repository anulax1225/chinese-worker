<?php

namespace App\Services\AI;

use App\Contracts\AIBackendInterface;
use App\DTOs\AIResponse;
use App\Models\Agent;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
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
        $this->timeout = $config['timeout'] ?? 120;
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
            $response = $this->client->post('/api/generate', [
                'json' => [
                    'model' => $this->model,
                    'prompt' => $this->buildPrompt($agent, $context),
                    'stream' => false,
                    'options' => $this->options,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return new AIResponse(
                content: $data['response'] ?? '',
                model: $data['model'] ?? $this->model,
                tokensUsed: ($data['eval_count'] ?? 0) + ($data['prompt_eval_count'] ?? 0),
                finishReason: $data['done'] ? 'stop' : 'length',
                metadata: [
                    'total_duration' => $data['total_duration'] ?? null,
                    'load_duration' => $data['load_duration'] ?? null,
                    'prompt_eval_count' => $data['prompt_eval_count'] ?? 0,
                    'eval_count' => $data['eval_count'] ?? 0,
                ]
            );
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
            $response = $this->client->post('/api/generate', [
                'json' => [
                    'model' => $this->model,
                    'prompt' => $this->buildPrompt($agent, $context),
                    'stream' => true,
                    'options' => $this->options,
                ],
                'stream' => true,
            ]);

            $body = $response->getBody();
            $fullContent = '';
            $lastData = [];

            while (! $body->eof()) {
                $line = $this->readLine($body);

                if (empty($line)) {
                    continue;
                }

                $data = json_decode($line, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }

                if (isset($data['response'])) {
                    $fullContent .= $data['response'];
                    $callback($data['response']);
                }

                if (! empty($data['done'])) {
                    $lastData = $data;
                    break;
                }
            }

            return new AIResponse(
                content: $fullContent,
                model: $lastData['model'] ?? $this->model,
                tokensUsed: ($lastData['eval_count'] ?? 0) + ($lastData['prompt_eval_count'] ?? 0),
                finishReason: 'stop',
                metadata: [
                    'total_duration' => $lastData['total_duration'] ?? null,
                    'load_duration' => $lastData['load_duration'] ?? null,
                    'prompt_eval_count' => $lastData['prompt_eval_count'] ?? 0,
                    'eval_count' => $lastData['eval_count'] ?? 0,
                ]
            );
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
            'function_calling' => false,
            'vision' => false,
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

    protected function buildPrompt(Agent $agent, array $context): string
    {
        $prompt = "Agent: {$agent->name}\n";
        $prompt .= "Description: {$agent->description}\n\n";

        if (! empty($agent->code)) {
            $prompt .= "Instructions:\n{$agent->code}\n\n";
        }

        if (! empty($context['input'])) {
            $prompt .= "Input: {$context['input']}\n";
        }

        if (! empty($context['history'])) {
            $prompt .= "\nConversation History:\n";
            foreach ($context['history'] as $entry) {
                $prompt .= "{$entry['role']}: {$entry['content']}\n";
            }
        }

        return $prompt;
    }

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
