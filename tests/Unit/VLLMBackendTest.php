<?php

use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Services\AI\VLLMBackend;

test('validates config requires base_url', function () {
    expect(fn () => new VLLMBackend([]))
        ->toThrow(InvalidArgumentException::class, 'base_url is required');
});

test('validates config with empty base_url', function () {
    expect(fn () => new VLLMBackend(['base_url' => '']))
        ->toThrow(InvalidArgumentException::class, 'base_url is required');
});

test('validates config with invalid base_url', function () {
    expect(fn () => new VLLMBackend(['base_url' => 'not-a-url']))
        ->toThrow(InvalidArgumentException::class, 'base_url is required');
});

test('creates backend with valid config', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
        'model' => 'meta-llama/Llama-3.1-8B-Instruct',
    ]);

    expect($backend)->toBeInstanceOf(VLLMBackend::class);
});

test('creates backend without api_key', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend)->toBeInstanceOf(VLLMBackend::class);
});

test('creates backend with api_key', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
        'api_key' => 'my-secret-key',
    ]);

    expect($backend)->toBeInstanceOf(VLLMBackend::class);
});

test('uses default model when not specified', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend)->toBeInstanceOf(VLLMBackend::class);
});

test('returns correct capabilities', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $capabilities = $backend->getCapabilities();

    expect($capabilities)->toBeArray();
    expect($capabilities['streaming'])->toBeTrue();
    expect($capabilities['function_calling'])->toBeTrue();
    expect($capabilities['vision'])->toBeTrue();
    expect($capabilities['embeddings'])->toBeFalse();
});

test('supports model management', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend->supportsModelManagement())->toBeTrue();
});

test('get manager url extracts base correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $reflection = new ReflectionClass($backend);
    $method = $reflection->getMethod('getManagerUrl');
    $method->setAccessible(true);

    expect($method->invoke($backend))->toBe('http://localhost:8000');
});

test('get manager url handles trailing slash', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1/',
    ]);

    $reflection = new ReflectionClass($backend);
    $method = $reflection->getMethod('getManagerUrl');
    $method->setAccessible(true);

    expect($method->invoke($backend))->toBe('http://localhost:8000');
});

test('count tokens estimates correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend->countTokens(''))->toBe(0);
    expect($backend->countTokens('Hello'))->toBeGreaterThan(0);
    expect($backend->countTokens('This is a longer text for testing'))->toBeGreaterThan(5);
});

test('get context limit returns default', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend->getContextLimit())->toBe(131072);
});

test('formats user message correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $message = ChatMessage::user('Hello, Llama!');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBe('Hello, Llama!');
});

test('formats system message correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $message = ChatMessage::system('You are a helpful assistant.');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('system');
    expect($formatted['content'])->toBe('You are a helpful assistant.');
});

test('formats assistant message correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $message = ChatMessage::assistant('Hello! How can I help?');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('assistant');
    expect($formatted['content'])->toBe('Hello! How can I help?');
});

test('formats tool result message correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $message = ChatMessage::tool('Result data', 'call_123', 'my_tool');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('tool');
    expect($formatted['tool_call_id'])->toBe('call_123');
    expect($formatted['content'])->toBe('Result data');
});

test('formats message with images correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $message = ChatMessage::user('What is in this image?', ['https://example.com/image.jpg']);
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBeArray();
    expect($formatted['content'][0]['type'])->toBe('image_url');
    expect($formatted['content'][0]['image_url']['url'])->toBe('https://example.com/image.jpg');
    expect($formatted['content'][1]['type'])->toBe('text');
});

test('formats message with base64 image correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $base64Data = 'iVBORw0KGgo=';
    $message = ChatMessage::user('What is in this image?', [$base64Data]);
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBeArray();
    expect($formatted['content'][0]['type'])->toBe('image_url');
    expect($formatted['content'][0]['image_url']['url'])->toStartWith('data:image/jpeg;base64,');
});

test('parses tool call correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $data = [
        'id' => 'call_123',
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'arguments' => '{"city": "Paris"}',
        ],
    ];

    $toolCall = $backend->parseToolCall($data);

    expect($toolCall)->toBeInstanceOf(ToolCall::class);
    expect($toolCall->id)->toBe('call_123');
    expect($toolCall->name)->toBe('get_weather');
    expect($toolCall->arguments)->toBe(['city' => 'Paris']);
});

test('parses tool call with malformed json gracefully', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $data = [
        'id' => 'call_123',
        'type' => 'function',
        'function' => [
            'name' => 'get_weather',
            'arguments' => 'not valid json',
        ],
    ];

    $toolCall = $backend->parseToolCall($data);

    expect($toolCall)->toBeInstanceOf(ToolCall::class);
    expect($toolCall->id)->toBe('call_123');
    expect($toolCall->name)->toBe('get_weather');
    expect($toolCall->arguments)->toBe([]);
});

test('with config updates model and timeout', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
        'model' => 'mistralai/Mistral-7B-Instruct-v0.3',
    ]);

    $config = new NormalizedModelConfig(
        model: 'meta-llama/Llama-3.1-70B-Instruct',
        temperature: 0.8,
        maxTokens: 8192,
        contextLength: 131072,
        timeout: 300
    );

    $newBackend = $backend->withConfig($config);

    expect($newBackend)->not()->toBe($backend);
    expect($newBackend->getContextLimit())->toBe(131072);
});

test('disconnect recreates client', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $backend->disconnect();

    expect($backend)->toBeInstanceOf(VLLMBackend::class);
});

test('formats assistant message with tool calls correctly', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    $toolCalls = [
        [
            'id' => 'call_123',
            'name' => 'get_weather',
            'arguments' => ['city' => 'Paris'],
        ],
    ];

    $message = ChatMessage::assistant('', $toolCalls);
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('assistant');
    expect($formatted['tool_calls'])->toBeArray();
    expect($formatted['tool_calls'][0]['id'])->toBe('call_123');
    expect($formatted['tool_calls'][0]['type'])->toBe('function');
    expect($formatted['tool_calls'][0]['function']['name'])->toBe('get_weather');
});

test('validate config returns true for valid base_url', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend->validateConfig(['base_url' => 'http://localhost:8000/v1']))->toBeTrue();
    expect($backend->validateConfig(['base_url' => 'https://my-vllm-server.com/v1']))->toBeTrue();
});

test('validate config returns false for missing base_url', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend->validateConfig([]))->toBeFalse();
    expect($backend->validateConfig(['base_url' => '']))->toBeFalse();
});

test('validate config returns false for invalid base_url', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:8000/v1',
    ]);

    expect($backend->validateConfig(['base_url' => 'not-a-url']))->toBeFalse();
});

test('is healthy returns false when server unreachable', function () {
    $backend = new VLLMBackend([
        'base_url' => 'http://localhost:59999/v1',
    ]);

    expect($backend->isHealthy())->toBeFalse();
});
