<?php

use App\DTOs\ChatMessage;
use App\Models\Agent;
use App\Models\User;
use App\Services\AI\OllamaBackend;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Config;

describe('OllamaBackend', function () {
    beforeEach(function () {
        Config::set('ai.backends.ollama', [
            'driver' => 'ollama',
            'base_url' => 'http://ollama:11434',
            'model' => 'llama3.1',
            'timeout' => 120,
            'options' => [
                'temperature' => 0.7,
                'num_ctx' => 4096,
            ],
        ]);

        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'name' => 'Test Agent',
            'description' => 'A test agent',
        ]);
    });

    test('validates config correctly', function () {
        $backend = new OllamaBackend(config('ai.backends.ollama'));

        expect($backend->validateConfig([
            'base_url' => 'http://ollama:11434',
            'model' => 'llama3.1',
        ]))->toBeTrue();

        expect($backend->validateConfig([
            'base_url' => 'invalid-url',
            'model' => 'llama3.1',
        ]))->toBeFalse();

        expect($backend->validateConfig([
            'model' => 'llama3.1',
        ]))->toBeFalse();
    });

    test('throws exception for invalid config', function () {
        new OllamaBackend([
            'base_url' => 'invalid-url',
            'model' => 'llama3.1',
        ]);
    })->throws(InvalidArgumentException::class);

    test('returns correct capabilities', function () {
        $backend = new OllamaBackend(config('ai.backends.ollama'));
        $capabilities = $backend->getCapabilities();

        expect($capabilities)->toBeArray()
            ->and($capabilities['streaming'])->toBeTrue()
            ->and($capabilities['function_calling'])->toBeTrue()
            ->and($capabilities['vision'])->toBeTrue()
            ->and($capabilities['embeddings'])->toBeTrue();
    });

    test('can execute chat and return response', function () {
        $mockResponse = json_encode([
            'model' => 'llama3.1',
            'message' => [
                'role' => 'assistant',
                'content' => 'Hello! How can I help you today?',
            ],
            'done' => true,
            'total_duration' => 1000000000,
            'load_duration' => 100000000,
            'prompt_eval_count' => 10,
            'eval_count' => 15,
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $mockResponse),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        // Use reflection to inject mock client
        $reflection = new ReflectionClass($backend);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($backend, $client);

        $response = $backend->execute($this->agent, [
            'input' => 'Hello',
        ]);

        expect($response->content)->toBe('Hello! How can I help you today?')
            ->and($response->model)->toBe('llama3.1')
            ->and($response->tokensUsed)->toBe(25)
            ->and($response->finishReason)->toBe('stop')
            ->and($response->metadata)->toHaveKey('total_duration')
            ->and($response->metadata['prompt_eval_count'])->toBe(10)
            ->and($response->metadata['eval_count'])->toBe(15);
    });

    test('can execute chat with tool calls', function () {
        $mockResponse = json_encode([
            'model' => 'llama3.1',
            'message' => [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [
                    [
                        'id' => 'call_123',
                        'function' => [
                            'name' => 'get_weather',
                            'arguments' => ['location' => 'Paris'],
                        ],
                    ],
                ],
            ],
            'done' => true,
            'total_duration' => 1000000000,
            'prompt_eval_count' => 10,
            'eval_count' => 5,
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $mockResponse),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        $reflection = new ReflectionClass($backend);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($backend, $client);

        // Pass tools via context (as client tools would be passed)
        $response = $backend->execute($this->agent, [
            'input' => 'What is the weather in Paris?',
            'tools' => [
                [
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_weather',
                        'description' => 'Get weather for a location',
                        'parameters' => [
                            'type' => 'object',
                            'properties' => [
                                'location' => [
                                    'type' => 'string',
                                    'description' => 'The city name',
                                ],
                            ],
                            'required' => ['location'],
                        ],
                    ],
                ],
            ],
        ]);

        expect($response->content)->toBe('')
            ->and($response->finishReason)->toBe('tool_calls')
            ->and($response->hasToolCalls())->toBeTrue()
            ->and($response->toolCalls)->toHaveCount(1)
            ->and($response->toolCalls[0]->name)->toBe('get_weather')
            ->and($response->toolCalls[0]->arguments)->toBe(['location' => 'Paris']);
    });

    test('can list available models', function () {
        $mockResponse = json_encode([
            'models' => [
                [
                    'name' => 'llama3.1:latest',
                    'modified_at' => '2024-01-01T00:00:00Z',
                    'size' => 4000000000,
                    'digest' => 'abc123',
                ],
                [
                    'name' => 'codellama:latest',
                    'modified_at' => '2024-01-02T00:00:00Z',
                    'size' => 5000000000,
                    'digest' => 'def456',
                ],
            ],
        ]);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $mockResponse),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        // Use reflection to inject mock client
        $reflection = new ReflectionClass($backend);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($backend, $client);

        $models = $backend->listModels();

        expect($models)->toHaveCount(2)
            ->and($models[0]['name'])->toBe('llama3.1:latest')
            ->and($models[0]['size'])->toBe(4000000000)
            ->and($models[1]['name'])->toBe('codellama:latest');
    });

    test('throws exception when API request fails', function () {
        $mock = new MockHandler([
            new Response(500, [], 'Internal Server Error'),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        // Use reflection to inject mock client
        $reflection = new ReflectionClass($backend);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($backend, $client);

        $backend->execute($this->agent, ['input' => 'test']);
    })->throws(RuntimeException::class, 'Ollama API request failed');

    test('builds messages correctly with conversation history', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        // Use reflection to access protected method
        $reflection = new ReflectionClass($backend);
        $method = $reflection->getMethod('buildMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($backend, $this->agent, [
            'input' => 'What is PHP?',
            'messages' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
        ]);

        expect($messages)->toHaveCount(4)
            ->and($messages[0])->toBeInstanceOf(ChatMessage::class)
            ->and($messages[0]->role)->toBe('system')
            ->and($messages[0]->content)->toContain('A test agent')
            ->and($messages[1]->role)->toBe('user')
            ->and($messages[1]->content)->toBe('Hello')
            ->and($messages[2]->role)->toBe('assistant')
            ->and($messages[2]->content)->toBe('Hi there!')
            ->and($messages[3]->role)->toBe('user')
            ->and($messages[3]->content)->toBe('What is PHP?');
    });

    test('sanitizes tool names for Ollama', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        $reflection = new ReflectionClass($backend);
        $method = $reflection->getMethod('sanitizeToolName');
        $method->setAccessible(true);

        expect($method->invoke($backend, 'my-tool'))->toBe('my-tool')
            ->and($method->invoke($backend, 'my_tool'))->toBe('my_tool')
            ->and($method->invoke($backend, 'My Tool!'))->toBe('My_Tool_')
            ->and($method->invoke($backend, 'tool@special#chars'))->toBe('tool_special_chars');
    });

    test('can stream execute and return aggregated response', function () {
        $mockResponses = [
            json_encode(['message' => ['content' => 'Hello'], 'done' => false]),
            json_encode(['message' => ['content' => ' there'], 'done' => false]),
            json_encode([
                'message' => ['content' => '!'],
                'done' => true,
                'model' => 'llama3.1',
                'total_duration' => 1000000000,
                'prompt_eval_count' => 5,
                'eval_count' => 10,
            ]),
        ];

        $streamBody = implode("\n", $mockResponses);

        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $streamBody),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $client = new Client(['handler' => $handlerStack]);

        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        // Use reflection to inject mock client
        $reflection = new ReflectionClass($backend);
        $property = $reflection->getProperty('client');
        $property->setAccessible(true);
        $property->setValue($backend, $client);

        $chunks = [];
        $response = $backend->streamExecute($this->agent, ['input' => 'Hello'], function ($chunk) use (&$chunks) {
            $chunks[] = $chunk;
        });

        expect($response->content)->toBe('Hello there!')
            ->and($response->model)->toBe('llama3.1')
            ->and($response->tokensUsed)->toBe(15)
            ->and($chunks)->toHaveCount(3)
            ->and($chunks[0])->toBe('Hello')
            ->and($chunks[1])->toBe(' there')
            ->and($chunks[2])->toBe('!');
    });

    test('handles vision input with images', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        $reflection = new ReflectionClass($backend);
        $method = $reflection->getMethod('buildMessages');
        $method->setAccessible(true);

        $messages = $method->invoke($backend, $this->agent, [
            'input' => 'What do you see?',
            'images' => ['base64_encoded_image_data'],
        ]);

        $lastMessage = end($messages);

        expect($lastMessage->role)->toBe('user')
            ->and($lastMessage->content)->toBe('What do you see?')
            ->and($lastMessage->images)->toBe(['base64_encoded_image_data']);
    });

    test('returns zero tokens for empty text', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        expect($backend->countTokens(''))->toBe(0);
    });

    test('estimates tokens using character count', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        $text = 'Hello, how are you today?';
        $tokenCount = $backend->countTokens($text);

        // Estimation is ceil(strlen / 4)
        expect($tokenCount)->toBe((int) ceil(mb_strlen($text) / 4));
    });

    test('returns default context limit', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        expect($backend->getContextLimit())->toBe(4096);
    });

    test('returns context limit from normalized config', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        $normalizedConfig = new \App\DTOs\NormalizedModelConfig(
            model: 'llama3.1',
            temperature: 0.7,
            topP: 0.9,
            topK: 40,
            contextLength: 8192,
            maxTokens: 2048,
            timeout: 120,
            validationWarnings: []
        );

        $configuredBackend = $backend->withConfig($normalizedConfig);

        expect($configuredBackend->getContextLimit())->toBe(8192);
    });
});
