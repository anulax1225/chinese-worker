<?php

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
            'code' => 'You are a helpful assistant.',
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
            ->and($capabilities['function_calling'])->toBeFalse()
            ->and($capabilities['vision'])->toBeFalse()
            ->and($capabilities['embeddings'])->toBeTrue();
    });

    test('can execute prompt and return response', function () {
        $mockResponse = json_encode([
            'model' => 'llama3.1',
            'response' => 'Hello! How can I help you today?',
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

    test('builds prompt correctly with agent context', function () {
        $config = config('ai.backends.ollama');
        $backend = new OllamaBackend($config);

        // Use reflection to access protected method
        $reflection = new ReflectionClass($backend);
        $method = $reflection->getMethod('buildPrompt');
        $method->setAccessible(true);

        $prompt = $method->invoke($backend, $this->agent, [
            'input' => 'What is PHP?',
            'history' => [
                ['role' => 'user', 'content' => 'Hello'],
                ['role' => 'assistant', 'content' => 'Hi there!'],
            ],
        ]);

        expect($prompt)->toContain('Agent: Test Agent')
            ->and($prompt)->toContain('Description: A test agent')
            ->and($prompt)->toContain('Instructions:')
            ->and($prompt)->toContain('You are a helpful assistant.')
            ->and($prompt)->toContain('Input: What is PHP?')
            ->and($prompt)->toContain('Conversation History:')
            ->and($prompt)->toContain('user: Hello')
            ->and($prompt)->toContain('assistant: Hi there!');
    });

    test('can stream execute and return aggregated response', function () {
        $mockResponses = [
            json_encode(['response' => 'Hello', 'done' => false]),
            json_encode(['response' => ' there', 'done' => false]),
            json_encode([
                'response' => '!',
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
});
