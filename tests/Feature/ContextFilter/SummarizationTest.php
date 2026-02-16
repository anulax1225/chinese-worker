<?php

use App\Contracts\AIBackendInterface;
use App\Contracts\TokenEstimator;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\Events\ConversationSummarized;
use App\Exceptions\SummarizationException;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\Message;
use App\Models\User;
use App\Services\AIBackendManager;
use App\Services\ContextFilter\Strategies\SummarizationStrategy;
use App\Services\ContextFilter\SummarizationService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([ConversationSummarized::class]);
});

describe('ConversationSummary Model', function () {
    test('can create a conversation summary', function () {
        $conversation = Conversation::factory()->create();

        $summary = ConversationSummary::factory()->create([
            'conversation_id' => $conversation->id,
            'from_position' => 1,
            'to_position' => 10,
            'content' => 'This is a summary of the conversation.',
            'token_count' => 100,
            'original_token_count' => 500,
        ]);

        expect($summary)
            ->toBeInstanceOf(ConversationSummary::class)
            ->conversation_id->toBe($conversation->id)
            ->from_position->toBe(1)
            ->to_position->toBe(10);
    });

    test('calculates compression ratio correctly', function () {
        $summary = ConversationSummary::factory()->create([
            'token_count' => 100,
            'original_token_count' => 500,
        ]);

        expect($summary->getCompressionRatio())->toBe(0.2);
    });

    test('calculates tokens saved correctly', function () {
        $summary = ConversationSummary::factory()->create([
            'token_count' => 100,
            'original_token_count' => 500,
        ]);

        expect($summary->getTokensSaved())->toBe(400);
    });

    test('belongs to conversation', function () {
        $conversation = Conversation::factory()->create();
        $summary = ConversationSummary::factory()->create([
            'conversation_id' => $conversation->id,
        ]);

        expect($summary->conversation)
            ->toBeInstanceOf(Conversation::class)
            ->id->toBe($conversation->id);
    });
});

describe('Message Model Summarization Fields', function () {
    test('message can be marked as synthetic', function () {
        $message = Message::factory()->synthetic()->create();

        expect($message->is_synthetic)->toBeTrue();
        expect($message->role)->toBe('system');
    });

    test('message can be marked as summarized', function () {
        $summary = ConversationSummary::factory()->create();
        $message = Message::factory()->summarized($summary->id)->create();

        expect($message->summarized)->toBeTrue();
        expect($message->summary_id)->toBe($summary->id);
    });

    test('scopeNotSummarized excludes summarized messages', function () {
        $conversation = Conversation::factory()->create();

        Message::factory()->for($conversation)->create(['summarized' => false]);
        Message::factory()->for($conversation)->create(['summarized' => true]);
        Message::factory()->for($conversation)->create(['summarized' => false]);

        $notSummarized = Message::query()
            ->where('conversation_id', $conversation->id)
            ->notSummarized()
            ->get();

        expect($notSummarized)->toHaveCount(2);
    });

    test('scopeSynthetic returns only synthetic messages', function () {
        $conversation = Conversation::factory()->create();

        Message::factory()->for($conversation)->create(['is_synthetic' => false]);
        Message::factory()->for($conversation)->synthetic()->create();
        Message::factory()->for($conversation)->create(['is_synthetic' => false]);

        $synthetic = Message::query()
            ->where('conversation_id', $conversation->id)
            ->synthetic()
            ->get();

        expect($synthetic)->toHaveCount(1);
    });
});

describe('SummarizationService', function () {
    test('throws exception when not enough messages to summarize', function () {
        $conversation = Conversation::factory()->create();
        $service = app(SummarizationService::class);

        $messages = [
            ChatMessage::user('Hello'),
            ChatMessage::assistant('Hi'),
        ];

        expect(fn () => $service->summarize($conversation, $messages, ['min_messages' => 5]))
            ->toThrow(SummarizationException::class);
    });

    test('creates summary record in database', function () {
        $conversation = Conversation::factory()->create();

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

        $service = new SummarizationService(
            $mockManager,
            app(TokenEstimator::class),
        );

        // Add messages to conversation
        for ($i = 0; $i < 6; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => "Message {$i}",
            ]);
        }

        $messages = array_map(
            fn ($m) => $m->toChatMessage(),
            $conversation->conversationMessages()->with(['toolCalls', 'attachments'])->get()->all()
        );

        $summary = $service->summarize($conversation, $messages, ['min_messages' => 5]);

        expect($summary)
            ->toBeInstanceOf(ConversationSummary::class)
            ->conversation_id->toBe($conversation->id)
            ->content->toBe('This is the summarized content.');

        $this->assertDatabaseHas('conversation_summaries', [
            'id' => $summary->id,
            'conversation_id' => $conversation->id,
        ]);
    });

    test('marks messages as summarized', function () {
        $conversation = Conversation::factory()->create();
        $summary = ConversationSummary::factory()->create([
            'conversation_id' => $conversation->id,
        ]);

        $messages = Message::factory()
            ->for($conversation)
            ->count(3)
            ->create();

        $messageIds = $messages->pluck('id')->toArray();

        $service = app(SummarizationService::class);
        $service->markAsSummarized($messageIds, $summary);

        foreach ($messageIds as $id) {
            $this->assertDatabaseHas('messages', [
                'id' => $id,
                'summarized' => true,
                'summary_id' => $summary->id,
            ]);
        }
    });
});

describe('SummarizationStrategy', function () {
    test('falls back to token_budget when not enough messages to summarize', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create([
                'context_limit' => 100,
            ]);

        // Add messages that will exceed budget but not enough to summarize (min 10)
        $conversation->addMessage(['role' => 'system', 'content' => 'System prompt']);
        for ($i = 0; $i < 4; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('Word ', 20),
            ]);
        }

        $strategy = app(SummarizationStrategy::class);

        $context = new FilterContext(
            messages: $conversation->getMessages(),
            contextLimit: 100,
            maxOutputTokens: 50,
            toolDefinitionTokens: 0,
            options: ['min_messages' => 10], // Require more messages than we have
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        // Should fall back because we don't have enough messages to summarize
        expect($result->strategyUsed)->toContain('fallback');
    });

    test('falls back to token_budget when summarization is disabled', function () {
        config(['ai.summarization.enabled' => false]);

        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create([
                'context_limit' => 100,
            ]);

        // Add many messages
        for ($i = 0; $i < 10; $i++) {
            $conversation->addMessage([
                'role' => $i % 2 === 0 ? 'user' : 'assistant',
                'content' => str_repeat('Word ', 50),
            ]);
        }

        $strategy = app(SummarizationStrategy::class);

        $context = new FilterContext(
            messages: $conversation->getMessages(),
            contextLimit: 100,
            maxOutputTokens: 50,
            toolDefinitionTokens: 0,
            options: [],
            agent: $agent,
            conversation: $conversation,
        );

        $result = $strategy->filter($context);

        expect($result->strategyUsed)->toContain('fallback');
    });

    test('returns noOp when no messages would be removed', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create();
        $conversation = Conversation::factory()
            ->for($user)
            ->for($agent)
            ->create([
                'context_limit' => 100000,
            ]);

        $conversation->addMessage(['role' => 'user', 'content' => 'Hello']);

        $strategy = app(SummarizationStrategy::class);

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
});

describe('ConversationSummarized Event', function () {
    test('event contains correct data', function () {
        $event = new ConversationSummarized(
            conversationId: 1,
            summaryId: 'summary-123',
            summarizedMessageCount: 10,
            originalTokenCount: 1000,
            summaryTokenCount: 200,
            compressionRatio: 0.2,
            backend: 'ollama',
            durationMs: 150.5,
        );

        expect($event)
            ->conversationId->toBe(1)
            ->summaryId->toBe('summary-123')
            ->summarizedMessageCount->toBe(10)
            ->originalTokenCount->toBe(1000)
            ->summaryTokenCount->toBe(200)
            ->compressionRatio->toBe(0.2)
            ->backend->toBe('ollama')
            ->durationMs->toBe(150.5);
    });

    test('calculates tokens saved correctly', function () {
        $event = new ConversationSummarized(
            conversationId: 1,
            summaryId: 'summary-123',
            summarizedMessageCount: 10,
            originalTokenCount: 1000,
            summaryTokenCount: 200,
            compressionRatio: 0.2,
            backend: 'ollama',
            durationMs: 150.5,
        );

        expect($event->getTokensSaved())->toBe(800);
    });

    test('calculates compression percentage correctly', function () {
        $event = new ConversationSummarized(
            conversationId: 1,
            summaryId: 'summary-123',
            summarizedMessageCount: 10,
            originalTokenCount: 1000,
            summaryTokenCount: 200,
            compressionRatio: 0.2,
            backend: 'ollama',
            durationMs: 150.5,
        );

        expect($event->getCompressionPercentage())->toBe(80.0);
    });
});
