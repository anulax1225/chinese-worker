<?php

use App\Contracts\AIBackendInterface;
use App\DTOs\AIResponse;
use App\Enums\SummaryStatus;
use App\Jobs\CreateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Services\AIBackendManager;

describe('CreateConversationSummaryJob', function () {
    test('transitions status from pending to processing to completed', function () {
        $conversation = Conversation::factory()->create();

        // Add enough messages
        for ($i = 0; $i < 6; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $summary = ConversationSummary::factory()
            ->for($conversation)
            ->pending()
            ->create([
                'from_position' => 0,
                'to_position' => 5,
            ]);

        // Mock the AI backend
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('execute')
            ->once()
            ->andReturn(new AIResponse(
                content: 'This is the summarized content.',
                model: 'llama3.1',
                tokensUsed: 50,
                finishReason: 'stop',
                toolCalls: [],
            ));

        $mockManager = Mockery::mock(AIBackendManager::class);
        $mockManager->shouldReceive('driver')
            ->andReturn($mockBackend);
        $mockManager->shouldReceive('forAgent')
            ->andReturn([
                'backend' => $mockBackend,
                'config' => null,
            ]);

        $this->app->instance(AIBackendManager::class, $mockManager);

        // Run the job
        $job = new CreateConversationSummaryJob($summary);
        $job->handle($mockManager, app(\App\Contracts\TokenEstimator::class));

        // Refresh and check status
        $summary->refresh();

        expect($summary->status)->toBe(SummaryStatus::Completed);
        expect($summary->content)->toBe('This is the summarized content.');
        expect($summary->token_count)->toBeGreaterThan(0);
    });

    test('sets status to failed on error', function () {
        $conversation = Conversation::factory()->create();

        // Add enough messages
        for ($i = 0; $i < 6; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $summary = ConversationSummary::factory()
            ->for($conversation)
            ->pending()
            ->create([
                'from_position' => 0,
                'to_position' => 5,
            ]);

        // Mock the AI backend to throw exception
        $mockBackend = Mockery::mock(AIBackendInterface::class);
        $mockBackend->shouldReceive('execute')
            ->once()
            ->andThrow(new \Exception('AI service unavailable'));

        $mockManager = Mockery::mock(AIBackendManager::class);
        $mockManager->shouldReceive('driver')
            ->andReturn($mockBackend);
        $mockManager->shouldReceive('forAgent')
            ->andReturn([
                'backend' => $mockBackend,
                'config' => null,
            ]);

        $this->app->instance(AIBackendManager::class, $mockManager);

        // Run the job and expect exception
        $job = new CreateConversationSummaryJob($summary);

        try {
            $job->handle($mockManager, app(\App\Contracts\TokenEstimator::class));
        } catch (\Exception $e) {
            // Expected
        }

        // Refresh and check status
        $summary->refresh();

        expect($summary->status)->toBe(SummaryStatus::Failed);
        expect($summary->error_message)->toContain('AI service unavailable');
    });

    test('fails when no messages in range', function () {
        $conversation = Conversation::factory()->create();

        $summary = ConversationSummary::factory()
            ->for($conversation)
            ->pending()
            ->create([
                'from_position' => 100,
                'to_position' => 200,
            ]);

        $job = new CreateConversationSummaryJob($summary, 100, 200);
        $job->handle(app(AIBackendManager::class), app(\App\Contracts\TokenEstimator::class));

        $summary->refresh();

        expect($summary->status)->toBe(SummaryStatus::Failed);
        expect($summary->error_message)->toContain('No messages found');
    });

    test('fails when insufficient messages', function () {
        $conversation = Conversation::factory()->create();

        // Add only 2 messages (less than minimum)
        $conversation->addMessage(['role' => 'user', 'content' => 'Hello']);
        $conversation->addMessage(['role' => 'assistant', 'content' => 'Hi']);

        $summary = ConversationSummary::factory()
            ->for($conversation)
            ->pending()
            ->create([
                'from_position' => 0,
                'to_position' => 1,
            ]);

        $job = new CreateConversationSummaryJob($summary);
        $job->handle(app(AIBackendManager::class), app(\App\Contracts\TokenEstimator::class));

        $summary->refresh();

        expect($summary->status)->toBe(SummaryStatus::Failed);
        expect($summary->error_message)->toContain('Insufficient messages');
    });
});
