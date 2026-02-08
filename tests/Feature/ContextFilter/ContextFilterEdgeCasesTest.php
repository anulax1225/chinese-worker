<?php

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Services\ContextFilter\Strategies\SlidingWindowStrategy;
use App\Services\ContextFilter\Strategies\TokenBudgetStrategy;
use App\Services\ContextFilter\TokenEstimators\CharRatioEstimator;

beforeEach(function () {
    $this->estimator = Mockery::mock(TokenEstimator::class);
});

afterEach(function () {
    Mockery::close();
});

test('handles empty message array', function () {
    $this->estimator->shouldReceive('estimate')->never();

    $strategy = new TokenBudgetStrategy($this->estimator);

    $context = new FilterContext(
        messages: [],
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->messages)->toBeEmpty();
    expect($result->originalCount)->toBe(0);
    expect($result->filteredCount)->toBe(0);
});

test('handles conversation with only system prompt', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'You are a helpful assistant.'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->role)->toBe('system');
    expect($result->hasRemovedMessages())->toBeFalse();
});

test('handles conversation that is all tool call chains', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Do three things'),
        new ChatMessage(
            role: 'assistant',
            content: 'First',
            toolCalls: [['id' => 'call_1', 'name' => 'tool1', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Result 1', toolCallId: 'call_1'),
        new ChatMessage(
            role: 'assistant',
            content: 'Second',
            toolCalls: [['id' => 'call_2', 'name' => 'tool2', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Result 2', toolCallId: 'call_2'),
        new ChatMessage(
            role: 'assistant',
            content: 'Third',
            toolCalls: [['id' => 'call_3', 'name' => 'tool3', 'arguments' => []]]
        ),
        new ChatMessage(role: 'tool', content: 'Result 3', toolCallId: 'call_3'),
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

    // All chains should be preserved
    expect($result->messages)->toHaveCount(9);

    // Verify all tool call chains are complete
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

    expect(count($toolCalls))->toBe(3);
    expect(count($toolResults))->toBe(3);
});

test('handles very tight budget gracefully', function () {
    // Each message has huge token count
    $this->estimator->shouldReceive('estimate')->andReturn(50000);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Hello'),
        new ChatMessage(role: 'assistant', content: 'Hi'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 10000, // Very small limit
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['budget_percentage' => 0.5],
    );

    $result = $strategy->filter($context);

    // Should have only system prompt
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->role)->toBe('system');
});

test('handles zero context limit', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Hello'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 0,
        maxOutputTokens: 0,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    // Should return only system prompt when no budget
    expect($result->messages)->toHaveCount(1);
    expect($result->messages[0]->role)->toBe('system');
});

test('mixed content token estimation produces varied estimates', function () {
    $estimator = new CharRatioEstimator;

    $proseMessage = new ChatMessage(role: 'user', content: 'Hello, how are you doing today? This is a simple prose message.');
    $jsonMessage = new ChatMessage(role: 'tool', content: '{"status": "success", "data": {"id": 123, "name": "test"}}');
    $codeMessage = new ChatMessage(
        role: 'assistant',
        content: <<<'CODE'
<?php
function test(): void {
    return $this->value;
}
CODE
    );

    $proseTokens = $estimator->estimate($proseMessage);
    $jsonTokens = $estimator->estimate($jsonMessage);
    $codeTokens = $estimator->estimate($codeMessage);

    // All should be positive
    expect($proseTokens)->toBeGreaterThan(0);
    expect($jsonTokens)->toBeGreaterThan(0);
    expect($codeTokens)->toBeGreaterThan(0);

    // JSON should have more tokens per char than prose
    $proseChars = mb_strlen($proseMessage->content);
    $jsonChars = mb_strlen($jsonMessage->content);

    $proseRatio = $proseTokens / $proseChars;
    $jsonRatio = $jsonTokens / $jsonChars;

    // JSON has lower chars-per-token (2.5 vs 4.0), so higher token ratio
    expect($jsonRatio)->toBeGreaterThan($proseRatio);
});

test('sliding window preserves system prompt even when window is tiny', function () {
    $strategy = new SlidingWindowStrategy;

    $messages = [
        new ChatMessage(role: 'system', content: 'Important system prompt'),
        new ChatMessage(role: 'user', content: 'Message 1'),
        new ChatMessage(role: 'assistant', content: 'Response 1'),
        new ChatMessage(role: 'user', content: 'Message 2'),
        new ChatMessage(role: 'assistant', content: 'Response 2'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: ['window_size' => 2], // System + 1 most recent message
    );

    $result = $strategy->filter($context);

    // Window size includes system prompt, so window_size=2 = system + 1 message
    expect($result->messages)->toHaveCount(2);
    expect($result->messages[0]->role)->toBe('system');
});

test('handles messages with identical content correctly', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    // Multiple messages with same content
    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Hello'),
        new ChatMessage(role: 'assistant', content: 'Hi'),
        new ChatMessage(role: 'user', content: 'Hello'), // Same as before
        new ChatMessage(role: 'assistant', content: 'Hi'), // Same as before
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    // All messages should be kept
    expect($result->messages)->toHaveCount(5);
});

test('FilterResult hasRemovedMessages works correctly', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Hello'),
    ];

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->hasRemovedMessages())->toBeFalse();
    expect($result->getRemovedCount())->toBe(0);
});

test('tool definitions reduce available budget', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(1000);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [
        new ChatMessage(role: 'system', content: 'System'),
        new ChatMessage(role: 'user', content: 'Hello'),
        new ChatMessage(role: 'assistant', content: 'Hi'),
    ];

    // Large tool definitions eat into budget
    $contextWithTools = new FilterContext(
        messages: $messages,
        contextLimit: 10000,
        maxOutputTokens: 2000,
        toolDefinitionTokens: 5000, // 5000 tokens for tool schemas
        options: ['budget_percentage' => 0.8],
    );

    $contextWithoutTools = new FilterContext(
        messages: $messages,
        contextLimit: 10000,
        maxOutputTokens: 2000,
        toolDefinitionTokens: 0,
        options: ['budget_percentage' => 0.8],
    );

    // Available budget: 10000 - 2000 - 5000 = 3000 * 0.8 = 2400 tokens
    expect($contextWithTools->getAvailableBudget())->toBe(3000);

    // Available budget: 10000 - 2000 - 0 = 8000 * 0.8 = 6400 tokens
    expect($contextWithoutTools->getAvailableBudget())->toBe(8000);
});

test('durationMs is tracked in FilterResult', function () {
    $this->estimator->shouldReceive('estimate')->andReturn(100);

    $strategy = new TokenBudgetStrategy($this->estimator);

    $messages = [];
    for ($i = 0; $i < 100; $i++) {
        $messages[] = new ChatMessage(role: 'user', content: "Message {$i}");
    }

    $context = new FilterContext(
        messages: $messages,
        contextLimit: 100000,
        maxOutputTokens: 4096,
        toolDefinitionTokens: 0,
        options: [],
    );

    $result = $strategy->filter($context);

    expect($result->durationMs)->toBeGreaterThan(0);
});
