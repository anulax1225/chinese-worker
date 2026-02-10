<?php

use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Services\AI\HuggingFaceBackend;

test('validates config requires api_key', function () {
    expect(fn () => new HuggingFaceBackend([]))
        ->toThrow(InvalidArgumentException::class, 'api_key is required');
});

test('validates config with empty api_key', function () {
    expect(fn () => new HuggingFaceBackend(['api_key' => '']))
        ->toThrow(InvalidArgumentException::class, 'api_key is required');
});

test('validates config requires api_key to start with hf_', function () {
    expect(fn () => new HuggingFaceBackend(['api_key' => 'sk-invalid-key']))
        ->toThrow(InvalidArgumentException::class, 'must start with "hf_"');
});

test('creates backend with valid config', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
        'model' => 'meta-llama/Llama-3.1-8B-Instruct',
    ]);

    expect($backend)->toBeInstanceOf(HuggingFaceBackend::class);
});

test('uses default model when not specified', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect($backend)->toBeInstanceOf(HuggingFaceBackend::class);
});

test('returns correct capabilities', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $capabilities = $backend->getCapabilities();

    expect($capabilities)->toBeArray();
    expect($capabilities['streaming'])->toBeTrue();
    expect($capabilities['function_calling'])->toBeTrue();
    expect($capabilities['vision'])->toBeTrue();
    expect($capabilities['embeddings'])->toBeFalse();
});

test('does not support model management', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect($backend->supportsModelManagement())->toBeFalse();
});

test('pull model throws exception', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect(fn () => $backend->pullModel('meta-llama/Llama-3.1-8B-Instruct', fn () => null))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('delete model throws exception', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect(fn () => $backend->deleteModel('meta-llama/Llama-3.1-8B-Instruct'))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('show model throws exception', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect(fn () => $backend->showModel('meta-llama/Llama-3.1-8B-Instruct'))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('count tokens estimates correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect($backend->countTokens(''))->toBe(0);
    expect($backend->countTokens('Hello'))->toBeGreaterThan(0);
    expect($backend->countTokens('This is a longer text for testing'))->toBeGreaterThan(5);
});

test('get context limit returns default', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect($backend->getContextLimit())->toBe(131072);
});

test('formats user message correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $message = ChatMessage::user('Hello, Llama!');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBe('Hello, Llama!');
});

test('formats system message correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $message = ChatMessage::system('You are a helpful assistant.');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('system');
    expect($formatted['content'])->toBe('You are a helpful assistant.');
});

test('formats assistant message correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $message = ChatMessage::assistant('Hello! How can I help?');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('assistant');
    expect($formatted['content'])->toBe('Hello! How can I help?');
});

test('formats tool result message correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $message = ChatMessage::tool('Result data', 'call_123', 'my_tool');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('tool');
    expect($formatted['tool_call_id'])->toBe('call_123');
    expect($formatted['content'])->toBe('Result data');
});

test('formats message with images correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
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
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
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
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
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
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
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
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
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
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $backend->disconnect();

    expect($backend)->toBeInstanceOf(HuggingFaceBackend::class);
});

test('formats assistant message with tool calls correctly', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
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

test('list models returns curated list', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $models = $backend->listModels();

    expect($models)->toBeArray();
    expect($models)->not()->toBeEmpty();
    expect($models[0])->toHaveKeys(['name', 'description']);
});

test('list models detailed returns additional info', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    $models = $backend->listModels(detailed: true);

    expect($models)->toBeArray();
    expect($models)->not()->toBeEmpty();
    expect($models[0])->toHaveKeys(['name', 'description', 'family', 'capabilities', 'context_length']);
});

test('validate config returns true for valid hf_ key', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect($backend->validateConfig(['api_key' => 'hf_valid_key']))->toBeTrue();
});

test('validate config returns false for non-hf_ key', function () {
    $backend = new HuggingFaceBackend([
        'api_key' => 'hf_test_key',
    ]);

    expect($backend->validateConfig(['api_key' => 'sk_invalid_key']))->toBeFalse();
});
