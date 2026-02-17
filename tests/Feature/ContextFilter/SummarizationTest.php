<?php

use App\Contracts\AIBackendInterface;
use App\Contracts\TokenEstimator;
use App\DTOs\AIResponse;
use App\DTOs\ChatMessage;
use App\Events\ConversationSummarized;
use App\Exceptions\SummarizationException;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Services\AIBackendManager;
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
