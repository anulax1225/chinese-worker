<?php

use App\DTOs\TokenUsage;

describe('TokenUsage', function () {
    test('can create token usage with all parameters', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            contextLimit: 4096
        );

        expect($tokenUsage->promptTokens)->toBe(100)
            ->and($tokenUsage->completionTokens)->toBe(50)
            ->and($tokenUsage->totalTokens)->toBe(150)
            ->and($tokenUsage->contextLimit)->toBe(4096);
    });

    test('can create token usage without context limit', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150
        );

        expect($tokenUsage->contextLimit)->toBeNull();
    });

    test('calculates usage percentage correctly', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 2048,
            contextLimit: 4096
        );

        expect($tokenUsage->getUsagePercentage())->toBe(50.0);
    });

    test('returns null usage percentage when no context limit', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150
        );

        expect($tokenUsage->getUsagePercentage())->toBeNull();
    });

    test('detects when approaching context limit', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 3500,
            contextLimit: 4096
        );

        expect($tokenUsage->isApproachingLimit(0.8))->toBeTrue();
        expect($tokenUsage->isApproachingLimit(0.9))->toBeFalse();
    });

    test('returns false for approaching limit when no context limit', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150
        );

        expect($tokenUsage->isApproachingLimit())->toBeFalse();
    });

    test('calculates remaining tokens correctly', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 1000,
            contextLimit: 4096
        );

        expect($tokenUsage->getRemainingTokens())->toBe(3096);
    });

    test('returns null remaining tokens when no context limit', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150
        );

        expect($tokenUsage->getRemainingTokens())->toBeNull();
    });

    test('can convert to array', function () {
        $tokenUsage = new TokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            totalTokens: 150,
            contextLimit: 4096
        );

        $array = $tokenUsage->toArray();

        expect($array)->toBe([
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'context_limit' => 4096,
            'remaining_tokens' => 3946,
            'usage_percentage' => 3.7,
        ]);
    });

    test('can create from array', function () {
        $array = [
            'prompt_tokens' => 100,
            'completion_tokens' => 50,
            'total_tokens' => 150,
            'context_limit' => 4096,
        ];

        $tokenUsage = TokenUsage::fromArray($array);

        expect($tokenUsage->promptTokens)->toBe(100)
            ->and($tokenUsage->completionTokens)->toBe(50)
            ->and($tokenUsage->totalTokens)->toBe(150)
            ->and($tokenUsage->contextLimit)->toBe(4096);
    });
});
