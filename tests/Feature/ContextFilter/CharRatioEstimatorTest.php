<?php

use App\DTOs\ChatMessage;
use App\Services\ContextFilter\TokenEstimators\CharRatioEstimator;

test('estimates tokens for prose content', function () {
    $estimator = new CharRatioEstimator;
    $message = new ChatMessage(role: 'user', content: 'Hello, how are you today?');

    $tokens = $estimator->estimate($message);

    // 26 chars / 4 chars per token = 6.5, ceil = 7, with safety margin
    expect($tokens)->toBeGreaterThan(0);
    expect($tokens)->toBeLessThan(20);
});

test('estimates tokens for json content with lower ratio', function () {
    $estimator = new CharRatioEstimator;
    $jsonContent = '{"name": "test", "value": 123, "nested": {"key": "value"}}';
    $message = new ChatMessage(role: 'tool', content: $jsonContent);

    $tokens = $estimator->estimate($message);

    // JSON uses 2.5 chars per token, so should produce more tokens than prose of same length
    $proseMessage = new ChatMessage(role: 'user', content: str_repeat('a', strlen($jsonContent)));
    $proseTokens = $estimator->estimate($proseMessage);

    expect($tokens)->toBeGreaterThan($proseTokens);
});

test('estimates tokens for code content', function () {
    $estimator = new CharRatioEstimator;
    $codeContent = <<<'CODE'
<?php
function test(): void {
    return $this->value;
}
CODE;
    $message = new ChatMessage(role: 'assistant', content: $codeContent);

    $tokens = $estimator->estimate($message);

    // Code uses 3.0 chars per token
    expect($tokens)->toBeGreaterThan(0);
});

test('returns zero for empty content', function () {
    $estimator = new CharRatioEstimator;
    $message = new ChatMessage(role: 'user', content: '');

    $tokens = $estimator->estimate($message);

    expect($tokens)->toBe(0);
});

test('supports all models', function () {
    $estimator = new CharRatioEstimator;

    expect($estimator->supports('gpt-4'))->toBeTrue();
    expect($estimator->supports('claude-3'))->toBeTrue();
    expect($estimator->supports('llama'))->toBeTrue();
    expect($estimator->supports('any-model'))->toBeTrue();
});
