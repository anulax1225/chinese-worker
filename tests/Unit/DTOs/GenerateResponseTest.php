<?php

use App\DTOs\AIResponse;
use App\DTOs\GenerateResponse;

test('creates response with all parameters', function () {
    $response = new GenerateResponse(
        content: 'Generated text',
        model: 'llama3.1',
        done: true,
        doneReason: 'stop',
        thinking: 'Thought process',
        totalDuration: 100_000_000,
        loadDuration: 10_000_000,
        promptEvalCount: 10,
        promptEvalDuration: 20_000_000,
        evalCount: 50,
        evalDuration: 70_000_000,
        logprobs: [['token' => 'test', 'logprob' => -0.5]],
        createdAt: '2024-01-01T00:00:00Z',
    );

    expect($response->content)->toBe('Generated text')
        ->and($response->model)->toBe('llama3.1')
        ->and($response->done)->toBeTrue()
        ->and($response->doneReason)->toBe('stop')
        ->and($response->thinking)->toBe('Thought process')
        ->and($response->totalDuration)->toBe(100_000_000)
        ->and($response->loadDuration)->toBe(10_000_000)
        ->and($response->promptEvalCount)->toBe(10)
        ->and($response->promptEvalDuration)->toBe(20_000_000)
        ->and($response->evalCount)->toBe(50)
        ->and($response->evalDuration)->toBe(70_000_000)
        ->and($response->logprobs)->toBe([['token' => 'test', 'logprob' => -0.5]])
        ->and($response->createdAt)->toBe('2024-01-01T00:00:00Z');
});

test('creates response from Ollama data', function () {
    $data = [
        'response' => 'Hello there',
        'model' => 'mistral',
        'done' => true,
        'done_reason' => 'stop',
        'thinking' => 'Let me think...',
        'total_duration' => 500_000_000,
        'load_duration' => 50_000_000,
        'prompt_eval_count' => 5,
        'prompt_eval_duration' => 100_000_000,
        'eval_count' => 20,
        'eval_duration' => 350_000_000,
        'created_at' => '2024-06-15T12:00:00Z',
    ];

    $response = GenerateResponse::fromOllamaResponse($data);

    expect($response->content)->toBe('Hello there')
        ->and($response->model)->toBe('mistral')
        ->and($response->done)->toBeTrue()
        ->and($response->doneReason)->toBe('stop')
        ->and($response->thinking)->toBe('Let me think...')
        ->and($response->totalDuration)->toBe(500_000_000)
        ->and($response->loadDuration)->toBe(50_000_000)
        ->and($response->promptEvalCount)->toBe(5)
        ->and($response->promptEvalDuration)->toBe(100_000_000)
        ->and($response->evalCount)->toBe(20)
        ->and($response->evalDuration)->toBe(350_000_000)
        ->and($response->createdAt)->toBe('2024-06-15T12:00:00Z');
});

test('fromOllamaResponse handles missing fields', function () {
    $data = [
        'response' => 'Minimal response',
        'model' => 'test-model',
        'done' => true,
    ];

    $response = GenerateResponse::fromOllamaResponse($data);

    expect($response->content)->toBe('Minimal response')
        ->and($response->model)->toBe('test-model')
        ->and($response->done)->toBeTrue()
        ->and($response->doneReason)->toBeNull()
        ->and($response->thinking)->toBeNull()
        ->and($response->totalDuration)->toBeNull()
        ->and($response->evalCount)->toBeNull();
});

test('fromOllamaResponse handles empty data', function () {
    $response = GenerateResponse::fromOllamaResponse([]);

    expect($response->content)->toBe('')
        ->and($response->model)->toBe('')
        ->and($response->done)->toBeTrue();
});

test('getTokensUsed calculates total tokens', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
        promptEvalCount: 15,
        evalCount: 30,
    );

    expect($response->getTokensUsed())->toBe(45);
});

test('getTokensUsed handles null values', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
    );

    expect($response->getTokensUsed())->toBe(0);
});

test('getTokensUsed handles partial null values', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
        promptEvalCount: 10,
    );

    expect($response->getTokensUsed())->toBe(10);
});

test('getTokensPerSecond calculates correctly', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
        evalCount: 100,
        evalDuration: 2_000_000_000, // 2 seconds in nanoseconds
    );

    expect($response->getTokensPerSecond())->toBe(50.0);
});

test('getTokensPerSecond returns null when data missing', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
    );

    expect($response->getTokensPerSecond())->toBeNull();
});

test('getTokensPerSecond returns null when duration is zero', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
        evalCount: 100,
        evalDuration: 0,
    );

    expect($response->getTokensPerSecond())->toBeNull();
});

test('getTotalDurationMs converts nanoseconds to milliseconds', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
        totalDuration: 500_000_000, // 500ms in nanoseconds
    );

    expect($response->getTotalDurationMs())->toBe(500.0);
});

test('getTotalDurationMs returns null when not set', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
    );

    expect($response->getTotalDurationMs())->toBeNull();
});

test('toAIResponse converts correctly', function () {
    $response = new GenerateResponse(
        content: 'Generated content',
        model: 'llama3.1',
        done: true,
        doneReason: 'stop',
        thinking: 'My thoughts',
        totalDuration: 100_000_000,
        promptEvalCount: 10,
        evalCount: 20,
        evalDuration: 50_000_000,
    );

    $aiResponse = $response->toAIResponse();

    expect($aiResponse)->toBeInstanceOf(AIResponse::class)
        ->and($aiResponse->content)->toBe('Generated content')
        ->and($aiResponse->model)->toBe('llama3.1')
        ->and($aiResponse->tokensUsed)->toBe(30)
        ->and($aiResponse->finishReason)->toBe('stop')
        ->and($aiResponse->thinking)->toBe('My thoughts')
        ->and($aiResponse->toolCalls)->toBe([])
        ->and($aiResponse->metadata)->toHaveKey('total_duration', 100_000_000)
        ->and($aiResponse->metadata)->toHaveKey('tokens_per_second');
});

test('toAIResponse uses incomplete finish reason when not done', function () {
    $response = new GenerateResponse(
        content: 'Partial',
        model: 'model',
        done: false,
    );

    $aiResponse = $response->toAIResponse();

    expect($aiResponse->finishReason)->toBe('incomplete');
});

test('toAIResponse defaults to stop finish reason', function () {
    $response = new GenerateResponse(
        content: 'Complete',
        model: 'model',
        done: true,
    );

    $aiResponse = $response->toAIResponse();

    expect($aiResponse->finishReason)->toBe('stop');
});

test('toArray returns all values', function () {
    $response = new GenerateResponse(
        content: 'Content',
        model: 'model',
        done: true,
        doneReason: 'stop',
        thinking: 'Thinking',
        totalDuration: 100_000_000,
        evalCount: 20,
        evalDuration: 50_000_000,
    );

    $array = $response->toArray();

    expect($array)->toHaveKey('content', 'Content')
        ->toHaveKey('model', 'model')
        ->toHaveKey('done', true)
        ->toHaveKey('done_reason', 'stop')
        ->toHaveKey('thinking', 'Thinking')
        ->toHaveKey('total_duration', 100_000_000)
        ->toHaveKey('eval_count', 20)
        ->toHaveKey('tokens_used')
        ->toHaveKey('tokens_per_second');
});

test('toArray excludes null values', function () {
    $response = new GenerateResponse(
        content: 'Test',
        model: 'model',
        done: true,
    );

    $array = $response->toArray();

    expect($array)->toHaveKey('content')
        ->toHaveKey('model')
        ->toHaveKey('done')
        ->not->toHaveKey('done_reason')
        ->not->toHaveKey('thinking')
        ->not->toHaveKey('logprobs');
});
