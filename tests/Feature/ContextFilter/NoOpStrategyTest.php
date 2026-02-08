<?php

use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Services\ContextFilter\Strategies\NoOpStrategy;

test('returns noop as strategy name', function () {
    $strategy = new NoOpStrategy;

    expect($strategy->name())->toBe('noop');
});

test('validates any options without error', function () {
    $strategy = new NoOpStrategy;

    $strategy->validateOptions([]);
    $strategy->validateOptions(['any' => 'option']);
    $strategy->validateOptions(['budget_percentage' => 0.8]);

    expect(true)->toBeTrue(); // No exception means success
});

test('returns all messages unchanged', function () {
    $strategy = new NoOpStrategy;

    $messages = [
        new ChatMessage(role: 'system', content: 'You are helpful.'),
        new ChatMessage(role: 'user', content: 'Hello'),
        new ChatMessage(role: 'assistant', content: 'Hi there!'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 128000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->messages)->toHaveCount(3);
    expect($result->originalCount)->toBe(3);
    expect($result->filteredCount)->toBe(3);
    expect($result->removedMessageIds)->toBeEmpty();
    expect($result->strategyUsed)->toBe('noop');
    expect($result->hasRemovedMessages())->toBeFalse();
});

test('handles empty message array', function () {
    $strategy = new NoOpStrategy;

    $context = new FilterContext(
        messages: [],
        contextLimit: 128000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->messages)->toBeEmpty();
    expect($result->originalCount)->toBe(0);
    expect($result->filteredCount)->toBe(0);
});
