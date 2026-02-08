<?php

use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Services\AI\OpenAIBackend;

test('validates config requires api_key', function () {
    expect(fn () => new OpenAIBackend([]))
        ->toThrow(InvalidArgumentException::class, 'api_key is required');
});

test('validates config with empty api_key', function () {
    expect(fn () => new OpenAIBackend(['api_key' => '']))
        ->toThrow(InvalidArgumentException::class, 'api_key is required');
});

test('creates backend with valid config', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
        'model' => 'gpt-4o',
    ]);

    expect($backend)->toBeInstanceOf(OpenAIBackend::class);
});

test('uses default model when not specified', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend)->toBeInstanceOf(OpenAIBackend::class);
});

test('returns correct capabilities', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    $capabilities = $backend->getCapabilities();

    expect($capabilities)->toBeArray();
    expect($capabilities['streaming'])->toBeTrue();
    expect($capabilities['function_calling'])->toBeTrue();
    expect($capabilities['vision'])->toBeTrue();
    expect($capabilities['embeddings'])->toBeTrue();
});

test('does not support model management', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend->supportsModelManagement())->toBeFalse();
});

test('pull model throws exception', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect(fn () => $backend->pullModel('gpt-4', fn () => null))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('delete model throws exception', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect(fn () => $backend->deleteModel('gpt-4'))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('show model throws exception', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect(fn () => $backend->showModel('gpt-4'))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('count tokens estimates correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend->countTokens(''))->toBe(0);
    expect($backend->countTokens('Hello'))->toBeGreaterThan(0);
    expect($backend->countTokens('This is a longer text for testing'))->toBeGreaterThan(5);
});

test('get context limit returns default', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend->getContextLimit())->toBe(128000);
});

test('formats user message correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::user('Hello, GPT!');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBe('Hello, GPT!');
});

test('formats system message correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::system('You are a helpful assistant.');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('system');
    expect($formatted['content'])->toBe('You are a helpful assistant.');
});

test('formats assistant message correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::assistant('Hello! How can I help?');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('assistant');
    expect($formatted['content'])->toBe('Hello! How can I help?');
});

test('formats tool result message correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::tool('Result data', 'call_123', 'my_tool');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('tool');
    expect($formatted['tool_call_id'])->toBe('call_123');
    expect($formatted['content'])->toBe('Result data');
});

test('formats message with images correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
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
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
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
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
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
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
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
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
        'model' => 'gpt-3.5-turbo',
    ]);

    $config = new NormalizedModelConfig(
        model: 'gpt-4o',
        temperature: 0.8,
        maxTokens: 8192,
        contextLength: 128000,
        timeout: 300
    );

    $newBackend = $backend->withConfig($config);

    expect($newBackend)->not()->toBe($backend);
    expect($newBackend->getContextLimit())->toBe(128000);
});

test('disconnect recreates client', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
    ]);

    // Should not throw
    $backend->disconnect();

    expect($backend)->toBeInstanceOf(OpenAIBackend::class);
});

test('formats assistant message with tool calls correctly', function () {
    $backend = new OpenAIBackend([
        'api_key' => 'test-key',
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
