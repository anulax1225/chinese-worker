<?php

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Exceptions\InvalidOptionsException;
use App\Services\ContextFilter\Strategies\TokenBudgetStrategy;

beforeEach(function () {
    $this->estimator = Mockery::mock(TokenEstimator::class);
});

afterEach(function () {
    Mockery::close();
});

test('returns token_budget as strategy name', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    expect($strategy->name())->toBe('token_budget');
});

test('validates budget_percentage must be between 0 and 1', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    expect(fn () => $strategy->validateOptions(['budget_percentage' => 0]))
        ->toThrow(InvalidOptionsException::class);

    expect(fn () => $strategy->validateOptions(['budget_percentage' => 1.5]))
        ->toThrow(InvalidOptionsException::class);

    expect(fn () => $strategy->validateOptions(['budget_percentage' => -0.5]))
        ->toThrow(InvalidOptionsException::class);
});

test('validates reserve_tokens must be non-negative', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    expect(fn () => $strategy->validateOptions(['reserve_tokens' => -100]))
        ->toThrow(InvalidOptionsException::class);
});

test('accepts valid options', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    $strategy->validateOptions(['budget_percentage' => 0.8]);
    $strategy->validateOptions(['budget_percentage' => 1.0]);
    $strategy->validateOptions(['reserve_tokens' => 1000]);
    $strategy->validateOptions([]);

    expect(true)->toBeTrue();
});

test('keeps all messages when under budget', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System prompt'),
        new ChatMessage(role: 'user', content: 'Hello'),
        new ChatMessage(role: 'assistant', content: 'Hi!'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['budget_percentage' => 0.8],
    );

    $result = $strategy->filter($context);

    // 3 messages * 100 tokens = 300 tokens, well under budget
    expect($result->messages)->toHaveCount(3);
    expect($result->hasRemovedMessages())->toBeFalse();
});

test('removes oldest messages when exceeding budget', function () {
    // Each message has 10000 tokens
    $this->estimator->shouldReceive('estimate')->andReturn(10000);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [];
    $messages[] = new ChatMessage(role: 'system', content: 'System prompt');
    for ($i = 1; $i <= 10; $i++) {
        $messages[] = new ChatMessage(role: $i % 2 === 0 ? 'assistant' : 'user', content: "Message {$i}");
    }

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 50000, // 50k limit
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['budget_percentage' => 0.8], // ~36k available for messages
    );

    $result = $strategy->filter($context);

    // Budget = (50000 - 4096 - 0) * 0.8 = ~36723 tokens
    // System (10k) + ~2 more messages (20k) = 30k, fits in budget
    expect($result->filteredCount)->toBeLessThan(11);
    expect($result->hasRemovedMessages())->toBeTrue();

    // System prompt should still be present
    expect($result->messages[0]->role)->toBe('system');
});

test('uses cached token count when available', function () {
    // Should not call estimator when tokenCount is set
    $this->estimator->shouldReceive('estimate')->never();

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System', tokenCount: 50),
        new ChatMessage(role: 'user', content: 'Hello', tokenCount: 10),
        new ChatMessage(role: 'assistant', content: 'Hi!', tokenCount: 10),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->messages)->toHaveCount(3);
});

test('accounts for available budget correctly', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    $context = new FilterContext(
        messages: [],
        contextLimit: 100000,
        maxOutputTokens: 10000,
        toolDefinitionTokens: 5000,
        options: [],
    );

    // Available = 100000 - 10000 - 5000 = 85000
    expect($context->getAvailableBudget())->toBe(85000);
});
