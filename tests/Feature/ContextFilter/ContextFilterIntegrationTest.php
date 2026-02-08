<?php

use App\Events\ContextFiltered;
use App\Events\ContextFilterOptionsInvalid;
use App\Events\ContextFilterResolutionFailed;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ContextFilter\ContextFilterManager;
use App\Services\ConversationService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([
        ContextFiltered::class,
        ContextFilterResolutionFailed::class,
        ContextFilterOptionsInvalid::class,
    ]);
});

test('automatic filtering triggers at threshold', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create([
        'context_threshold' => 0.8,
        'context_strategy' => 'token_budget',
        'context_options' => ['budget_percentage' => 0.9],
    ]);

    $conversation = Conversation::factory()
        ->for($user)
        ->for($agent)
        ->create([
            'context_limit' => 10000,
            'total_tokens' => 8500, // 85% usage, above 80% threshold
        ]);

    // Add some messages
    $conversation->addMessage([
        'role' => 'system',
        'content' => 'You are helpful.',
    ]);
    $conversation->addMessage([
        'role' => 'user',
        'content' => 'Hello',
    ]);
    $conversation->addMessage([
        'role' => 'assistant',
        'content' => 'Hi there!',
    ]);

    $service = app(ConversationService::class);
    $messages = $service->getMessagesForAI($conversation);

    // Filtering should have been applied
    Event::assertDispatched(ContextFiltered::class);
});

test('manual filtering via forceFilter flag', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create();

    $conversation = Conversation::factory()
        ->for($user)
        ->for($agent)
        ->create([
            'context_limit' => 100000,
            'total_tokens' => 100, // Very low usage
        ]);

    $conversation->addMessage([
        'role' => 'system',
        'content' => 'System',
    ]);
    $conversation->addMessage([
        'role' => 'user',
        'content' => 'Hello',
    ]);

    $service = app(ConversationService::class);

    // Force filter even though under threshold
    $messages = $service->getMessagesForAI($conversation, forceFilter: true);

    Event::assertDispatched(ContextFiltered::class);
});

test('skip filtering via skipFilter flag', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create([
        'context_threshold' => 0.5,
    ]);

    $conversation = Conversation::factory()
        ->for($user)
        ->for($agent)
        ->create([
            'context_limit' => 1000,
            'total_tokens' => 900, // 90% usage
        ]);

    $conversation->addMessage([
        'role' => 'system',
        'content' => 'System',
    ]);

    $service = app(ConversationService::class);

    // Skip filter even though above threshold
    $messages = $service->getMessagesForAI($conversation, skipFilter: true);

    Event::assertNotDispatched(ContextFiltered::class);
});

test('strategy resolution failure falls back to NoOp', function () {
    $manager = app(ContextFilterManager::class);

    // Try to resolve a non-existent strategy
    $strategy = $manager->resolve('non_existent_strategy');

    // Should fall back to NoOp
    expect($strategy->name())->toBe('noop');

    // Event should be dispatched
    Event::assertDispatched(ContextFilterResolutionFailed::class, function ($event) {
        return $event->strategyName === 'non_existent_strategy';
    });
});

test('agent with invalid context options emits event on save', function () {
    Event::fake([ContextFilterOptionsInvalid::class]);

    $agent = Agent::factory()->create([
        'context_strategy' => 'token_budget',
    ]);

    // Try to set invalid options
    expect(function () use ($agent) {
        $agent->context_options = ['budget_percentage' => 2.0]; // Invalid: > 1
        $agent->save();
    })->toThrow(Exception::class);
});

test('ContextFiltered event contains correct data', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create([
        'context_strategy' => 'sliding_window',
        'context_options' => ['window_size' => 5],
    ]);

    $conversation = Conversation::factory()
        ->for($user)
        ->for($agent)
        ->create([
            'context_limit' => 1000,
            'total_tokens' => 900, // Above threshold
        ]);

    // Add more than 5 messages
    $conversation->addMessage(['role' => 'system', 'content' => 'System']);
    for ($i = 0; $i < 10; $i++) {
        $role = $i % 2 === 0 ? 'user' : 'assistant';
        $conversation->addMessage(['role' => $role, 'content' => "Message {$i}"]);
    }

    $service = app(ConversationService::class);
    $messages = $service->getMessagesForAI($conversation, forceFilter: true);

    Event::assertDispatched(ContextFiltered::class, function ($event) use ($conversation) {
        return $event->conversationId === $conversation->id
            && $event->strategyUsed === 'sliding_window'
            && $event->originalCount === 11 // system + 10 messages
            && $event->filteredCount <= 6 // window_size + 1 for system
            && $event->durationMs > 0;
    });
});

test('uses agent-specific strategy and options', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create([
        'context_strategy' => 'sliding_window',
        'context_options' => ['window_size' => 4], // Total window including system prompt
    ]);

    $conversation = Conversation::factory()
        ->for($user)
        ->for($agent)
        ->create([
            'context_limit' => 1000,
            'total_tokens' => 900,
        ]);

    // Add 6 messages (system + 5 user/assistant)
    $conversation->addMessage(['role' => 'system', 'content' => 'System']);
    for ($i = 0; $i < 5; $i++) {
        $role = $i % 2 === 0 ? 'user' : 'assistant';
        $conversation->addMessage(['role' => $role, 'content' => "Message {$i}"]);
    }

    $service = app(ConversationService::class);
    $messages = $service->getMessagesForAI($conversation, forceFilter: true);

    // Window size 4 includes system prompt, so we get system + 3 recent messages
    expect(count($messages))->toBe(4);
});

test('falls back to default strategy when agent has no strategy', function () {
    $user = User::factory()->create();
    $agent = Agent::factory()->create([
        'context_strategy' => null,
        'context_options' => null,
    ]);

    $conversation = Conversation::factory()
        ->for($user)
        ->for($agent)
        ->create([
            'context_limit' => 10000,
            'total_tokens' => 9000,
        ]);

    $conversation->addMessage(['role' => 'system', 'content' => 'System']);
    $conversation->addMessage(['role' => 'user', 'content' => 'Hello']);

    $service = app(ConversationService::class);
    $messages = $service->getMessagesForAI($conversation, forceFilter: true);

    // Default strategy (token_budget) should be used
    Event::assertDispatched(ContextFiltered::class, function ($event) {
        return $event->strategyUsed === 'token_budget';
    });
});

test('manager lists all available strategies', function () {
    $manager = app(ContextFilterManager::class);

    $strategies = $manager->availableStrategies();

    expect($strategies)->toContain('noop');
    expect($strategies)->toContain('sliding_window');
    expect($strategies)->toContain('token_budget');
});
