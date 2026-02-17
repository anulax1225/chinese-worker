<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ContextFilter\ContextFilterManager;

describe('Context Filter Pipeline', function () {
    test('single string context_strategy still works (backward compat)', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'user_id' => $user->id,
            'context_strategy' => 'token_budget',
            'context_strategies' => null,
        ]);
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        // Add some messages
        for ($i = 0; $i < 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $manager = app(ContextFilterManager::class);
        $result = $manager->filterForConversation($conversation);

        expect($result->strategyUsed)->toBe('token_budget');
        expect($result->metadata['strategies_applied'])->toBe(['token_budget']);
    });

    test('context_strategies array takes precedence over context_strategy', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'user_id' => $user->id,
            'context_strategy' => 'sliding_window',
            'context_strategies' => ['summary_boundary', 'token_budget'],
        ]);
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        for ($i = 0; $i < 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $manager = app(ContextFilterManager::class);
        $result = $manager->filterForConversation($conversation);

        // Should use context_strategies, not context_strategy
        expect($result->metadata['strategies_applied'])->toBe(['summary_boundary', 'token_budget']);
        expect($result->strategyUsed)->toBe('summary_boundary+token_budget');
    });

    test('pipeline runs strategies in order', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'user_id' => $user->id,
            'context_strategies' => ['noop', 'token_budget'],
        ]);
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        for ($i = 0; $i < 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $manager = app(ContextFilterManager::class);
        $result = $manager->filterForConversation($conversation);

        expect($result->metadata['strategies_applied'])->toBe(['noop', 'token_budget']);
        expect($result->metadata['pipeline_length'])->toBe(2);
    });

    test('getEffectiveContextStrategies returns strategies array when set', function () {
        $agent = Agent::factory()->make([
            'context_strategy' => 'sliding_window',
            'context_strategies' => ['summary_boundary', 'token_budget'],
        ]);

        expect($agent->getEffectiveContextStrategies())->toBe(['summary_boundary', 'token_budget']);
    });

    test('getEffectiveContextStrategies falls back to single strategy', function () {
        $agent = Agent::factory()->make([
            'context_strategy' => 'sliding_window',
            'context_strategies' => null,
        ]);

        expect($agent->getEffectiveContextStrategies())->toBe(['sliding_window']);
    });

    test('getEffectiveContextStrategies returns default when nothing set', function () {
        $agent = Agent::factory()->make([
            'context_strategy' => null,
            'context_strategies' => null,
        ]);

        $strategies = $agent->getEffectiveContextStrategies();

        expect($strategies)->toBeArray();
        expect($strategies)->toHaveCount(1);
    });

    test('validation rejects unknown strategy names in context_strategies', function () {
        $user = User::factory()->create();

        expect(fn () => Agent::factory()->create([
            'user_id' => $user->id,
            'context_strategies' => ['token_budget', 'nonexistent_strategy'],
        ]))->toThrow(InvalidArgumentException::class, 'Unknown context strategy: nonexistent_strategy');
    });

    test('hasStrategy returns true for registered strategies', function () {
        $manager = app(ContextFilterManager::class);

        expect($manager->hasStrategy('token_budget'))->toBeTrue();
        expect($manager->hasStrategy('noop'))->toBeTrue();
        expect($manager->hasStrategy('sliding_window'))->toBeTrue();
        expect($manager->hasStrategy('summary_boundary'))->toBeTrue();
    });

    test('hasStrategy returns false for unknown strategies', function () {
        $manager = app(ContextFilterManager::class);

        expect($manager->hasStrategy('unknown'))->toBeFalse();
        expect($manager->hasStrategy(''))->toBeFalse();
        expect($manager->hasStrategy('fake_strategy'))->toBeFalse();
    });

    test('strategyUsed joins multiple strategies with plus sign', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create([
            'user_id' => $user->id,
            'context_strategies' => ['noop', 'summary_boundary', 'token_budget'],
        ]);
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        $conversation->addMessage(['role' => 'user', 'content' => 'Hello']);

        $manager = app(ContextFilterManager::class);
        $result = $manager->filterForConversation($conversation);

        expect($result->strategyUsed)->toBe('noop+summary_boundary+token_budget');
    });
});
