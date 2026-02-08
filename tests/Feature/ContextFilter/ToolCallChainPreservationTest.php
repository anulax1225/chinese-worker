<?php

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Services\ContextFilter\Strategies\TokenBudgetStrategy;

beforeEach(function () {
    $this->estimator = Mockery::mock(TokenEstimator::class);
    $this->estimator->shouldReceive('estimate')->andReturn(100)->byDefault();
});

afterEach(function () {
    Mockery::close();
});

test('keeps tool result when tool call is kept', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System prompt'),
        new ChatMessage(role: 'user', content: 'Search for cats'),
        new ChatMessage(
            role: 'assistant',
            content: 'Searching...',
            toolCalls: [['id' => 'call_123', 'name' => 'search', 'arguments' => ['query' => 'cats']]]
        ),
        new ChatMessage(role: 'tool', content: 'Found cats.', toolCallId: 'call_123'),
        new ChatMessage(role: 'assistant', content: 'I found information about cats.'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['budget_percentage' => 0.8],
    );

    $result = $strategy->filter($context);

    // All messages should be kept
    expect($result->messages)->toHaveCount(5);

    // Verify tool call and response are both present
    $hasToolCall = false;
    $hasToolResult = false;

    foreach ($result->messages as $msg) {
        if (! empty($msg->toolCalls)) {
            foreach ($msg->toolCalls as $tc) {
                if (($tc['id'] ?? null) === 'call_123') {
                    $hasToolCall = true;
                }
            }
        }
        if ($msg->toolCallId === 'call_123') {
            $hasToolResult = true;
        }
    }

    expect($hasToolCall)->toBeTrue();
    expect($hasToolResult)->toBeTrue();
});

test('keeps tool call when tool result is kept', function () {
    // Very tight budget to force removal
    $this->estimator->shouldReceive('estimate')->andReturn(10000);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System prompt'),
        new ChatMessage(role: 'user', content: 'Old message 1'),
        new ChatMessage(role: 'assistant', content: 'Old response 1'),
        new ChatMessage(role: 'user', content: 'Old message 2'),
        new ChatMessage(role: 'assistant', content: 'Old response 2'),
        new ChatMessage(role: 'user', content: 'Search for cats'),
        new ChatMessage(
            role: 'assistant',
            content: 'Searching...',
            toolCalls: [['id' => 'call_abc', 'name' => 'search', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Found cats.', toolCallId: 'call_abc'),
        new ChatMessage(role: 'assistant', content: 'Done'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 60000, // Force some filtering
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['budget_percentage' => 0.5], // Tight budget
    );

    $result = $strategy->filter($context);

    // Even if the tool call is removed initially, if tool result is kept,
    // the tool call should be added back to maintain integrity
    $hasToolCall = false;
    $hasToolResult = false;

    foreach ($result->messages as $msg) {
        if (! empty($msg->toolCalls)) {
            foreach ($msg->toolCalls as $tc) {
                if (($tc['id'] ?? null) === 'call_abc') {
                    $hasToolCall = true;
                }
            }
        }
        if ($msg->toolCallId === 'call_abc') {
            $hasToolResult = true;
        }
    }

    // If one is present, the other must also be present
    if ($hasToolCall || $hasToolResult) {
        expect($hasToolCall)->toBe($hasToolResult);
    }
});

test('preserves multiple tool call chains', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Do things'),
        new ChatMessage(
            role: 'assistant',
            content: 'Doing first',
            toolCalls: [['id' => 'call_1', 'name' => 'tool1', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Result 1', toolCallId: 'call_1'),
        new ChatMessage(
            role: 'assistant',
            content: 'Doing second',
            toolCalls: [['id' => 'call_2', 'name' => 'tool2', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Result 2', toolCallId: 'call_2'),
        new ChatMessage(role: 'assistant', content: 'All done'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    // Verify both chains are complete
    $toolCalls = [];
    $toolResults = [];

    foreach ($result->messages as $msg) {
        if (! empty($msg->toolCalls)) {
            foreach ($msg->toolCalls as $tc) {
                $toolCalls[] = $tc['id'] ?? null;
            }
        }
        if ($msg->toolCallId) {
            $toolResults[] = $msg->toolCallId;
        }
    }

    // Each tool call should have a corresponding result
    foreach ($toolCalls as $callId) {
        expect($toolResults)->toContain($callId);
    }
});

test('maintains original message order after chain preservation', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Start'),
        new ChatMessage(
            role: 'assistant',
            content: 'Tool call',
            toolCalls: [['id' => 'call_1', 'name' => 'tool', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Tool result', toolCallId: 'call_1'),
        new ChatMessage(role: 'assistant', content: 'End'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    // Check order is preserved
    $roles = array_map(fn ($m) => $m->role, $result->messages);

    expect($roles)->toBe(['system', 'user', 'assistant', 'tool', 'assistant']);
});

test('handles parallel tool calls correctly', function () {
    $strategy = new TokenBudgetStrategy($this->estimator);

    // Assistant makes multiple tool calls in one message
    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Do multiple things'),
        new ChatMessage(
            role: 'assistant',
            content: 'Doing both',
            toolCalls: [
                ['id' => 'call_a', 'name' => 'tool1', 'arguments' => []],
                ['id' => 'call_b', 'name' => 'tool2', 'arguments' => []],
            ]
        ),
        new ChatMessage(role: 'tool', content: 'Result A', toolCallId: 'call_a'),
        new ChatMessage(role: 'tool', content: 'Result B', toolCallId: 'call_b'),
        new ChatMessage(role: 'assistant', content: 'Both done'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    // All 6 messages should be kept
    expect($result->messages)->toHaveCount(6);

    // Both tool results should be present
    $toolResultIds = array_filter(
        array_map(fn ($m) => $m->toolCallId, $result->messages)
    );

    expect($toolResultIds)->toContain('call_a');
    expect($toolResultIds)->toContain('call_b');
});
