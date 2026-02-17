<?php

use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Enums\SummaryStatus;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\User;
use App\Services\ContextFilter\Strategies\SummaryBoundaryStrategy;

describe('SummaryBoundaryStrategy', function () {
    test('passes through when no summaries exist', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        // Add messages
        for ($i = 0; $i < 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $strategy = app(SummaryBoundaryStrategy::class);

        $context = new FilterContext(
            messages: $conversation->getMessages(),
            contextLimit: 100000,
            maxOutputTokens: 1000,
            toolDefinitionTokens: 0,
            options: [],
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        expect($result->hasRemovedMessages())->toBeFalse();
        expect($result->strategyUsed)->toBe('summary_boundary');
    });

    test('ignores pending summaries', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        // Add messages
        for ($i = 0; $i < 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        // Create a pending summary (should be ignored)
        ConversationSummary::factory()
            ->for($conversation)
            ->pending()
            ->create([
                'from_position' => 0,
                'to_position' => 3,
            ]);

        $strategy = app(SummaryBoundaryStrategy::class);

        $context = new FilterContext(
            messages: $conversation->getMessages(),
            contextLimit: 100000,
            maxOutputTokens: 1000,
            toolDefinitionTokens: 0,
            options: [],
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        // Should pass through since only completed summaries are used
        expect($result->hasRemovedMessages())->toBeFalse();
    });

    test('ignores failed summaries', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        // Add messages
        for ($i = 0; $i < 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        // Create a failed summary (should be ignored)
        ConversationSummary::factory()
            ->for($conversation)
            ->failed()
            ->create([
                'from_position' => 0,
                'to_position' => 3,
            ]);

        $strategy = app(SummaryBoundaryStrategy::class);

        $context = new FilterContext(
            messages: $conversation->getMessages(),
            contextLimit: 100000,
            maxOutputTokens: 1000,
            toolDefinitionTokens: 0,
            options: [],
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        expect($result->hasRemovedMessages())->toBeFalse();
    });

    test('clips at completed summary boundary', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        // Add messages with explicit positions
        for ($i = 0; $i < 10; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        // Create a completed summary covering positions 0-5
        ConversationSummary::factory()
            ->for($conversation)
            ->create([
                'status' => SummaryStatus::Completed,
                'from_position' => 0,
                'to_position' => 5,
                'content' => 'This is a summary of the conversation.',
            ]);

        $strategy = app(SummaryBoundaryStrategy::class);

        // Get messages with metadata
        $messages = $conversation->getMessages();

        $context = new FilterContext(
            messages: $messages,
            contextLimit: 100000,
            maxOutputTokens: 1000,
            toolDefinitionTokens: 0,
            options: [],
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        expect($result->strategyUsed)->toBe('summary_boundary');
        expect($result->metadata)->toHaveKey('summary_id');
        expect($result->metadata['boundary_position'])->toBe(5);
    });

    test('places summary as first user message', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create();

        // Add system prompt first
        $conversation->addMessage([
            'role' => 'system',
            'content' => 'You are a helpful assistant.',
        ]);

        // Add more messages
        for ($i = 1; $i <= 5; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'assistant' : 'user',
                'content' => "Message {$i}",
            ]);
        }

        // Create completed summary
        ConversationSummary::factory()
            ->for($conversation)
            ->create([
                'status' => SummaryStatus::Completed,
                'from_position' => 1,
                'to_position' => 3,
                'content' => 'Summary of messages 1-3.',
            ]);

        $strategy = app(SummaryBoundaryStrategy::class);
        $messages = $conversation->getMessages();

        $context = new FilterContext(
            messages: $messages,
            contextLimit: 100000,
            maxOutputTokens: 1000,
            toolDefinitionTokens: 0,
            options: [],
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        // First message should be system prompt
        expect($result->messages[0]->role)->toBe('system');
        expect($result->messages[0]->content)->toBe('You are a helpful assistant.');

        // Second message should be the summary (as user message)
        expect($result->messages[1]->role)->toBe('user');
        expect($result->messages[1]->content)->toContain('Previous Conversation Summary');
        expect($result->messages[1]->content)->toContain('Summary of messages 1-3.');
    });

    test('returns no-op when no conversation context', function () {
        $strategy = app(SummaryBoundaryStrategy::class);

        $messages = [
            ChatMessage::user('Hello'),
            ChatMessage::assistant('Hi there!'),
        ];

        $context = new FilterContext(
            messages: $messages,
            contextLimit: 100000,
            maxOutputTokens: 1000,
            toolDefinitionTokens: 0,
            options: [],
            agent: null,
            conversation: null,
        );

        $result = $strategy->filter($context);

        expect($result->hasRemovedMessages())->toBeFalse();
        expect($result->strategyUsed)->toBe('summary_boundary');
    });
});
