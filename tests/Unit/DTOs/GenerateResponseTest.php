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

test('creates response from full vLLM completions response', function () {
    $timestamp = 1_700_000_000;
    $data = [
        'model' => 'meta-llama/Llama-3.1-8B',
        'created' => $timestamp,
        'choices' => [
            [
                'text' => 'The answer is 42.',
                'finish_reason' => 'stop',
                'logprobs' => null,
            ],
        ],
        'usage' => [
            'prompt_tokens' => 15,
            'completion_tokens' => 8,
            'total_tokens' => 23,
        ],
    ];

    $response = GenerateResponse::fromVLLMResponse($data);

    expect($response->content)->toBe('The answer is 42.')
        ->and($response->model)->toBe('meta-llama/Llama-3.1-8B')
        ->and($response->done)->toBeTrue()
        ->and($response->doneReason)->toBe('stop')
        ->and($response->promptEvalCount)->toBe(15)
        ->and($response->evalCount)->toBe(8)
        ->and($response->createdAt)->toBe(date('c', $timestamp));
});

test('fromVLLMResponse always sets done to true', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'text', 'finish_reason' => 'length']],
    ]);

    expect($response->done)->toBeTrue();
});

test('fromVLLMResponse always sets thinking to null', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'text', 'finish_reason' => 'stop']],
    ]);

    expect($response->thinking)->toBeNull();
});

test('fromVLLMResponse always sets duration fields to null', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'text', 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 3],
    ]);

    expect($response->totalDuration)->toBeNull()
        ->and($response->loadDuration)->toBeNull()
        ->and($response->promptEvalDuration)->toBeNull()
        ->and($response->evalDuration)->toBeNull();
});

test('fromVLLMResponse defaults finish_reason to stop when absent', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'text']],
    ]);

    expect($response->doneReason)->toBe('stop');
});

test('fromVLLMResponse maps finish_reason length correctly', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'truncated', 'finish_reason' => 'length']],
    ]);

    expect($response->doneReason)->toBe('length');
});

test('fromVLLMResponse maps logprobs from first choice', function () {
    $logprobs = [
        ['token' => ' The', 'logprob' => -0.123],
        ['token' => ' answer', 'logprob' => -0.456],
    ];

    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [
            ['text' => 'The answer', 'finish_reason' => 'stop', 'logprobs' => $logprobs],
        ],
    ]);

    expect($response->logprobs)->toBe($logprobs);
});

test('fromVLLMResponse converts created unix timestamp to ISO 8601', function () {
    $timestamp = mktime(12, 0, 0, 6, 15, 2024);

    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'created' => $timestamp,
        'choices' => [['text' => 'text', 'finish_reason' => 'stop']],
    ]);

    expect($response->createdAt)->toBe(date('c', $timestamp));
});

test('fromVLLMResponse sets createdAt to null when created is absent', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'text', 'finish_reason' => 'stop']],
    ]);

    expect($response->createdAt)->toBeNull();
});

test('fromVLLMResponse handles empty choices gracefully', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'some-model',
        'choices' => [],
    ]);

    expect($response->content)->toBe('')
        ->and($response->doneReason)->toBe('stop')
        ->and($response->logprobs)->toBeNull();
});

test('fromVLLMResponse handles empty data gracefully', function () {
    $response = GenerateResponse::fromVLLMResponse([]);

    expect($response->content)->toBe('')
        ->and($response->model)->toBe('')
        ->and($response->done)->toBeTrue()
        ->and($response->promptEvalCount)->toBeNull()
        ->and($response->evalCount)->toBeNull()
        ->and($response->createdAt)->toBeNull();
});

test('fromVLLMResponse maps usage token counts correctly', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'result', 'finish_reason' => 'stop']],
        'usage' => [
            'prompt_tokens' => 120,
            'completion_tokens' => 45,
            'total_tokens' => 165,
        ],
    ]);

    expect($response->promptEvalCount)->toBe(120)
        ->and($response->evalCount)->toBe(45);
});

test('fromVLLMResponse sets token counts to null when usage is absent', function () {
    $response = GenerateResponse::fromVLLMResponse([
        'model' => 'model',
        'choices' => [['text' => 'text', 'finish_reason' => 'stop']],
    ]);

    expect($response->promptEvalCount)->toBeNull()
        ->and($response->evalCount)->toBeNull();
});
