<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\ConversationEventBroadcaster;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->agent = Agent::factory()->create([
        'user_id' => $this->user->id,
    ]);
    $this->conversation = Conversation::factory()->create([
        'user_id' => $this->user->id,
        'agent_id' => $this->agent->id,
        'turn_count' => 2,
        'total_tokens' => 150,
    ]);
    $this->broadcaster = new ConversationEventBroadcaster;
});

describe('ConversationEventBroadcaster', function () {
    test('broadcast publishes event to Redis channel', function () {
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) {
                expect($channel)->toBe("conversation:{$this->conversation->id}:events");

                $data = json_decode($payload, true);
                expect($data['event'])->toBe('test_event');
                expect($data['conversation_id'])->toBe($this->conversation->id);
                expect($data['data'])->toBe(['key' => 'value']);
                expect($data)->toHaveKey('timestamp');

                return true;
            });

        $this->broadcaster->broadcast($this->conversation, 'test_event', ['key' => 'value']);
    });

    test('textChunk broadcasts text chunk event', function () {
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) {
                $data = json_decode($payload, true);
                expect($data['event'])->toBe('text_chunk');
                expect($data['data']['chunk'])->toBe('Hello');
                expect($data['data']['type'])->toBe('content');
                expect($data['data']['conversation_id'])->toBe($this->conversation->id);

                return true;
            });

        $this->broadcaster->textChunk($this->conversation, 'Hello', 'content');
    });

    test('textChunk supports thinking type', function () {
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) {
                $data = json_decode($payload, true);
                expect($data['event'])->toBe('text_chunk');
                expect($data['data']['type'])->toBe('thinking');

                return true;
            });

        $this->broadcaster->textChunk($this->conversation, 'Let me think...', 'thinking');
    });

    test('toolRequest broadcasts tool request event', function () {
        $toolRequest = [
            'call_id' => 'call_123',
            'name' => 'bash',
            'arguments' => ['command' => 'ls -la'],
        ];

        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) use ($toolRequest) {
                $data = json_decode($payload, true);
                expect($data['event'])->toBe('tool_request');
                expect($data['data']['status'])->toBe('waiting_for_tool');
                expect($data['data']['tool_request'])->toBe($toolRequest);
                expect($data['data']['submit_url'])->toBe("/api/v1/conversations/{$this->conversation->id}/tool-results");
                expect($data['data']['stats'])->toHaveKeys(['turns', 'tokens']);

                return true;
            });

        $this->broadcaster->toolRequest($this->conversation, $toolRequest);
    });

    test('completed broadcasts completion event', function () {
        // Add a message to the conversation
        $this->conversation->addMessage([
            'role' => 'assistant',
            'content' => 'Task completed successfully.',
        ]);
        $this->conversation->refresh();

        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) {
                $data = json_decode($payload, true);
                expect($data['event'])->toBe('completed');
                expect($data['data']['status'])->toBe('completed');
                expect($data['data']['stats'])->toHaveKeys(['turns', 'tokens']);
                expect($data['data'])->toHaveKey('messages');

                return true;
            });

        $this->broadcaster->completed($this->conversation);
    });

    test('failed broadcasts failure event', function () {
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) {
                $data = json_decode($payload, true);
                expect($data['event'])->toBe('failed');
                expect($data['data']['status'])->toBe('failed');
                expect($data['data']['error'])->toBe('Something went wrong');
                expect($data['data']['stats'])->toHaveKeys(['turns', 'tokens']);

                return true;
            });

        $this->broadcaster->failed($this->conversation, 'Something went wrong');
    });

    test('processing broadcasts status changed event', function () {
        Redis::shouldReceive('publish')
            ->once()
            ->withArgs(function ($channel, $payload) {
                $data = json_decode($payload, true);
                expect($data['event'])->toBe('status_changed');
                expect($data['data']['status'])->toBe('processing');

                return true;
            });

        $this->broadcaster->processing($this->conversation);
    });

    test('broadcast handles Redis failure gracefully', function () {
        Redis::shouldReceive('publish')
            ->once()
            ->andThrow(new Exception('Redis connection failed'));

        // Should not throw - just log warning
        $this->broadcaster->broadcast($this->conversation, 'test', ['data' => 'value']);

        // No assertion needed - test passes if no exception thrown
        expect(true)->toBeTrue();
    });
});
