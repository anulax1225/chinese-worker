<?php

use App\DTOs\ChatMessage;
use App\DTOs\NormalizedModelConfig;
use App\DTOs\ToolCall;
use App\Services\AI\AnthropicBackend;

test('validates config requires api_key', function () {
    expect(fn () => new AnthropicBackend([]))
        ->toThrow(InvalidArgumentException::class, 'api_key is required');
});

test('validates config with empty api_key', function () {
    expect(fn () => new AnthropicBackend(['api_key' => '']))
        ->toThrow(InvalidArgumentException::class, 'api_key is required');
});

test('creates backend with valid config', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
        'model' => 'claude-sonnet-4-5-20250929',
    ]);

    expect($backend)->toBeInstanceOf(AnthropicBackend::class);
});

test('uses default model when not specified', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend)->toBeInstanceOf(AnthropicBackend::class);
});

test('returns correct capabilities', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $capabilities = $backend->getCapabilities();

    expect($capabilities)->toBeArray();
    expect($capabilities['streaming'])->toBeTrue();
    expect($capabilities['function_calling'])->toBeTrue();
    expect($capabilities['vision'])->toBeTrue();
    expect($capabilities['embeddings'])->toBeFalse();
});

test('does not support model management', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend->supportsModelManagement())->toBeFalse();
});

test('pull model throws exception', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect(fn () => $backend->pullModel('claude-3-opus', fn () => null))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('delete model throws exception', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect(fn () => $backend->deleteModel('claude-3-opus'))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('show model throws exception', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect(fn () => $backend->showModel('claude-3-opus'))
        ->toThrow(RuntimeException::class, 'not supported');
});

test('list models returns known models', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $models = $backend->listModels();

    expect($models)->toBeArray();
    expect($models)->not()->toBeEmpty();
    expect(collect($models)->pluck('name'))->toContain('claude-opus-4-6');
});

test('count tokens estimates correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend->countTokens(''))->toBe(0);
    expect($backend->countTokens('Hello'))->toBeGreaterThan(0);
    expect($backend->countTokens('This is a longer text for testing'))->toBeGreaterThan(5);
});

test('get context limit returns default', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    expect($backend->getContextLimit())->toBe(200000);
});

test('formats user message correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::user('Hello, Claude!');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBe('Hello, Claude!');
});

test('formats assistant message correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::assistant('Hello! How can I help?');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('assistant');
    expect($formatted['content'])->toBe('Hello! How can I help?');
});

test('formats tool result message correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::tool('Result data', 'toolu_123', 'my_tool');
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBeArray();
    expect($formatted['content'][0]['type'])->toBe('tool_result');
    expect($formatted['content'][0]['tool_use_id'])->toBe('toolu_123');
    expect($formatted['content'][0]['content'])->toBe('Result data');
});

test('formats message with images correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $message = ChatMessage::user('What is in this image?', ['https://example.com/image.jpg']);
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBeArray();
    expect($formatted['content'][0]['type'])->toBe('image');
    expect($formatted['content'][0]['source']['type'])->toBe('url');
    expect($formatted['content'][0]['source']['url'])->toBe('https://example.com/image.jpg');
    expect($formatted['content'][1]['type'])->toBe('text');
});

test('formats message with base64 image correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $base64Image = 'data:image/png;base64,iVBORw0KGgo=';
    $message = ChatMessage::user('What is in this image?', [$base64Image]);
    $formatted = $backend->formatMessage($message);

    expect($formatted['role'])->toBe('user');
    expect($formatted['content'])->toBeArray();
    expect($formatted['content'][0]['type'])->toBe('image');
    expect($formatted['content'][0]['source']['type'])->toBe('base64');
    expect($formatted['content'][0]['source']['media_type'])->toBe('image/png');
});

test('parses tool call correctly', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    $data = [
        'id' => 'toolu_123',
        'name' => 'get_weather',
        'input' => ['city' => 'Paris'],
    ];

    $toolCall = $backend->parseToolCall($data);

    expect($toolCall)->toBeInstanceOf(ToolCall::class);
    expect($toolCall->id)->toBe('toolu_123');
    expect($toolCall->name)->toBe('get_weather');
    expect($toolCall->arguments)->toBe(['city' => 'Paris']);
});

test('with config updates model and timeout', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
        'model' => 'claude-3-haiku',
    ]);

    $config = new NormalizedModelConfig(
        model: 'claude-opus-4-6',
        temperature: 0.8,
        maxTokens: 8192,
        contextLength: 200000,
        timeout: 300
    );

    $newBackend = $backend->withConfig($config);

    expect($newBackend)->not()->toBe($backend);
    expect($newBackend->getContextLimit())->toBe(200000);
});

test('disconnect recreates client', function () {
    $backend = new AnthropicBackend([
        'api_key' => 'test-key',
    ]);

    // Should not throw
    $backend->disconnect();

    expect($backend)->toBeInstanceOf(AnthropicBackend::class);
});
