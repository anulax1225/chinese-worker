<?php

use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Exceptions\InvalidOptionsException;
use App\Services\ContextFilter\Strategies\SlidingWindowStrategy;

test('returns sliding_window as strategy name', function () {
    $strategy = new SlidingWindowStrategy;

    expect($strategy->name())->toBe('sliding_window');
});

test('validates window_size must be positive integer', function () {
    $strategy = new SlidingWindowStrategy;

    expect(fn () => $strategy->validateOptions(['window_size' => 0]))
        ->toThrow(InvalidOptionsException::class);

    expect(fn () => $strategy->validateOptions(['window_size' => -1]))
        ->toThrow(InvalidOptionsException::class);

    expect(fn () => $strategy->validateOptions(['window_size' => 'ten']))
        ->toThrow(InvalidOptionsException::class);
});

test('accepts valid window_size', function () {
    $strategy = new SlidingWindowStrategy;

    $strategy->validateOptions(['window_size' => 10]);
    $strategy->validateOptions(['window_size' => 100]);
    $strategy->validateOptions([]); // Uses default

    expect(true)->toBeTrue();
});

test('keeps all messages when under window size', function () {
    $strategy = new SlidingWindowStrategy;

    $messages = [
        new ChatMessage(role: 'system', content: 'System prompt'),
        new ChatMessage(role: 'user', content: 'Hello'),
        new ChatMessage(role: 'assistant', content: 'Hi!'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 128000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['window_size' => 10],
    );

    $result = $strategy->filter($context);

    expect($result->messages)->toHaveCount(3);
    expect($result->hasRemovedMessages())->toBeFalse();
});

test('removes oldest messages when exceeding window size', function () {
    $strategy = new SlidingWindowStrategy;

    $messages = [
        new ChatMessage(role: 'system', content: 'System prompt'),
        new ChatMessage(role: 'user', content: 'Message 1'),
        new ChatMessage(role: 'assistant', content: 'Response 1'),
        new ChatMessage(role: 'user', content: 'Message 2'),
        new ChatMessage(role: 'assistant', content: 'Response 2'),
        new ChatMessage(role: 'user', content: 'Message 3'),
        new ChatMessage(role: 'assistant', content: 'Response 3'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 128000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['window_size' => 4],
    );

    $result = $strategy->filter($context);

    // Should keep system prompt + last 3 messages = 4
    expect($result->filteredCount)->toBe(4);
    expect($result->hasRemovedMessages())->toBeTrue();
    expect($result->getRemovedCount())->toBe(3);

    // System prompt should always be first
    expect($result->messages[0]->role)->toBe('system');
});

test('always preserves system prompt', function () {
    $strategy = new SlidingWindowStrategy;

    $messages = [
        new ChatMessage(role: 'system', content: 'Important system instructions'),
        new ChatMessage(role: 'user', content: 'User message'),
        new ChatMessage(role: 'assistant', content: 'Assistant response'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 128000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['window_size' => 2],
    );

    $result = $strategy->filter($context);

    // System prompt is preserved even with window_size = 2
    $hasSystemPrompt = false;
    foreach ($result->messages as $msg) {
        if ($msg->role === 'system') {
            $hasSystemPrompt = true;
            break;
        }
    }

    expect($hasSystemPrompt)->toBeTrue();
});
