<?php

use App\DTOs\GenerateRequest;

test('creates basic request with prompt', function () {
    $request = new GenerateRequest(prompt: 'Hello, world!');

    expect($request->prompt)->toBe('Hello, world!')
        ->and($request->suffix)->toBeNull()
        ->and($request->images)->toBeNull()
        ->and($request->format)->toBeNull()
        ->and($request->system)->toBeNull()
        ->and($request->think)->toBeNull()
        ->and($request->raw)->toBeFalse()
        ->and($request->maxTokens)->toBeNull()
        ->and($request->temperature)->toBeNull();
});

test('creates request with all parameters', function () {
    $request = new GenerateRequest(
        prompt: 'Complete this: ',
        suffix: ' and that is the end.',
        images: ['base64image1', 'base64image2'],
        format: 'json',
        system: 'You are a helpful assistant.',
        think: true,
        raw: true,
        keepAlive: '5m',
        maxTokens: 100,
        temperature: 0.7,
        topP: 0.9,
        topK: 40,
        minP: 0.05,
        seed: 42,
        stop: ['END'],
        contextLength: 4096,
        logprobs: true,
        topLogprobs: 5,
    );

    expect($request->prompt)->toBe('Complete this: ')
        ->and($request->suffix)->toBe(' and that is the end.')
        ->and($request->images)->toBe(['base64image1', 'base64image2'])
        ->and($request->format)->toBe('json')
        ->and($request->system)->toBe('You are a helpful assistant.')
        ->and($request->think)->toBeTrue()
        ->and($request->raw)->toBeTrue()
        ->and($request->keepAlive)->toBe('5m')
        ->and($request->maxTokens)->toBe(100)
        ->and($request->temperature)->toBe(0.7)
        ->and($request->topP)->toBe(0.9)
        ->and($request->topK)->toBe(40)
        ->and($request->minP)->toBe(0.05)
        ->and($request->seed)->toBe(42)
        ->and($request->stop)->toBe(['END'])
        ->and($request->contextLength)->toBe(4096)
        ->and($request->logprobs)->toBeTrue()
        ->and($request->topLogprobs)->toBe(5);
});

test('isValid returns true for non-empty prompt', function () {
    $request = new GenerateRequest(prompt: 'Hello');

    expect($request->isValid())->toBeTrue();
});

test('isValid returns false for empty prompt', function () {
    $request = new GenerateRequest(prompt: '');

    expect($request->isValid())->toBeFalse();
});

test('isValid returns false for whitespace-only prompt', function () {
    $request = new GenerateRequest(prompt: '   ');

    expect($request->isValid())->toBeFalse();
});

test('validate passes for valid request', function () {
    $request = new GenerateRequest(prompt: 'Hello', temperature: 0.7, topP: 0.9);

    $request->validate(); // Should not throw

    expect(true)->toBeTrue();
});

test('validate throws for empty prompt', function () {
    $request = new GenerateRequest(prompt: '');

    $request->validate();
})->throws(InvalidArgumentException::class, 'non-empty prompt');

test('validate throws for invalid think value', function () {
    $request = new GenerateRequest(prompt: 'Hello', think: 'invalid');

    $request->validate();
})->throws(InvalidArgumentException::class, 'think must be');

test('validate accepts valid think string values', function () {
    foreach (['high', 'medium', 'low'] as $level) {
        $request = new GenerateRequest(prompt: 'Hello', think: $level);
        $request->validate();
    }

    expect(true)->toBeTrue();
});

test('validate throws for temperature out of range', function () {
    $request = new GenerateRequest(prompt: 'Hello', temperature: 2.5);

    $request->validate();
})->throws(InvalidArgumentException::class, 'temperature must be');

test('validate throws for topP out of range', function () {
    $request = new GenerateRequest(prompt: 'Hello', topP: 1.5);

    $request->validate();
})->throws(InvalidArgumentException::class, 'topP must be');

test('validate throws for minP out of range', function () {
    $request = new GenerateRequest(prompt: 'Hello', minP: -0.1);

    $request->validate();
})->throws(InvalidArgumentException::class, 'minP must be');

test('toOllamaPayload generates correct structure', function () {
    $request = new GenerateRequest(
        prompt: 'Test prompt',
        system: 'System prompt',
        maxTokens: 100,
        temperature: 0.8,
    );

    $payload = $request->toOllamaPayload('llama3.1', false);

    expect($payload)->toHaveKey('model', 'llama3.1')
        ->toHaveKey('prompt', 'Test prompt')
        ->toHaveKey('stream', false)
        ->toHaveKey('system', 'System prompt')
        ->toHaveKey('options');

    expect($payload['options'])->toHaveKey('num_predict', 100)
        ->toHaveKey('temperature', 0.8);
});

test('toOllamaPayload includes all generation options', function () {
    $request = new GenerateRequest(
        prompt: 'Test',
        maxTokens: 200,
        temperature: 0.5,
        topP: 0.95,
        topK: 50,
        minP: 0.1,
        seed: 123,
        stop: ['STOP'],
        contextLength: 8192,
    );

    $payload = $request->toOllamaPayload('model', true);
    $options = $payload['options'];

    expect($options)->toHaveKey('num_predict', 200)
        ->toHaveKey('temperature', 0.5)
        ->toHaveKey('top_p', 0.95)
        ->toHaveKey('top_k', 50)
        ->toHaveKey('min_p', 0.1)
        ->toHaveKey('seed', 123)
        ->toHaveKey('stop', ['STOP'])
        ->toHaveKey('num_ctx', 8192);
});

test('toOllamaPayload includes images when provided', function () {
    $request = new GenerateRequest(
        prompt: 'Describe this image',
        images: ['base64data1', 'base64data2'],
    );

    $payload = $request->toOllamaPayload('llava', true);

    expect($payload)->toHaveKey('images', ['base64data1', 'base64data2']);
});

test('toOllamaPayload includes think when set', function () {
    $request = new GenerateRequest(prompt: 'Think about this', think: 'high');

    $payload = $request->toOllamaPayload('model', false);

    expect($payload)->toHaveKey('think', 'high');
});

test('toOllamaPayload includes format for structured output', function () {
    $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]];
    $request = new GenerateRequest(prompt: 'Generate JSON', format: $schema);

    $payload = $request->toOllamaPayload('model', false);

    expect($payload)->toHaveKey('format', $schema);
});

test('toOllamaPayload includes logprobs settings', function () {
    $request = new GenerateRequest(prompt: 'Test', logprobs: true, topLogprobs: 3);

    $payload = $request->toOllamaPayload('model', false);

    expect($payload)->toHaveKey('logprobs', true)
        ->toHaveKey('top_logprobs', 3);
});

test('toArray returns all set values', function () {
    $request = new GenerateRequest(
        prompt: 'Test',
        system: 'System',
        temperature: 0.7,
        maxTokens: 100,
    );

    $array = $request->toArray();

    expect($array)->toHaveKey('prompt', 'Test')
        ->toHaveKey('system', 'System')
        ->toHaveKey('temperature', 0.7)
        ->toHaveKey('max_tokens', 100)
        ->not->toHaveKey('suffix')
        ->not->toHaveKey('images');
});

test('toArray excludes null and default false values', function () {
    $request = new GenerateRequest(prompt: 'Just a prompt');

    $array = $request->toArray();

    expect($array)->toHaveKey('prompt')
        ->not->toHaveKey('raw')
        ->not->toHaveKey('suffix')
        ->not->toHaveKey('format');
});
