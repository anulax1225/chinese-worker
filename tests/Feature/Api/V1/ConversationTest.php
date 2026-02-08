<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('Conversation Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'ollama',
        ]);
        Sanctum::actingAs($this->user);
    });

    describe('Create Conversation', function () {
        test('user can create a conversation with an agent', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/conversations", [
                'title' => 'Test conversation',
                'client_type' => 'cli_web',
                'metadata' => ['key' => 'value'],
            ]);

            $response->assertStatus(201)
                ->assertJsonFragment([
                    'agent_id' => $this->agent->id,
                    'user_id' => $this->user->id,
                    'status' => 'active',
                    'turn_count' => 0,
                ]);
        });

        test('user cannot create conversation for another users agent', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->postJson("/api/v1/agents/{$otherAgent->id}/conversations");

            $response->assertStatus(403);
        });

        test('conversation creation validates input', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/conversations", [
                'title' => str_repeat('a', 300), // Too long
            ]);

            $response->assertStatus(422);
        });
    });

    describe('List Conversations', function () {
        test('user can list their conversations', function () {
            Conversation::factory()->count(3)->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
            ]);

            $response = $this->getJson('/api/v1/conversations');

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'agent_id',
                            'user_id',
                            'status',
                            'messages',
                            'turn_count',
                            'total_tokens',
                        ],
                    ],
                    'links',
                    'meta',
                ]);

            expect($response->json('data'))->toHaveCount(3);
        });

        test('user can filter conversations by agent', function () {
            $agent2 = Agent::factory()->create(['user_id' => $this->user->id]);

            Conversation::factory()->count(2)->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
            ]);

            Conversation::factory()->count(3)->create([
                'user_id' => $this->user->id,
                'agent_id' => $agent2->id,
            ]);

            $response = $this->getJson("/api/v1/conversations?agent_id={$this->agent->id}");

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(2);
        });

        test('user can filter conversations by status', function () {
            Conversation::factory()->count(2)->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'active',
            ]);

            Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'completed',
            ]);

            $response = $this->getJson('/api/v1/conversations?status=active');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(2);
        });

        test('user only sees their own conversations', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);

            Conversation::factory()->count(2)->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
            ]);

            Conversation::factory()->count(3)->create([
                'user_id' => $otherUser->id,
                'agent_id' => $otherAgent->id,
            ]);

            $response = $this->getJson('/api/v1/conversations');

            $response->assertStatus(200);
            expect($response->json('data'))->toHaveCount(2);
        });
    });

    describe('Show Conversation', function () {
        test('user can view their conversation', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
            ]);

            $response = $this->getJson("/api/v1/conversations/{$conversation->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'id' => $conversation->id,
                    'agent_id' => $this->agent->id,
                    'user_id' => $this->user->id,
                ]);
        });

        test('user cannot view another users conversation', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);
            $conversation = Conversation::factory()->create([
                'user_id' => $otherUser->id,
                'agent_id' => $otherAgent->id,
            ]);

            $response = $this->getJson("/api/v1/conversations/{$conversation->id}");

            $response->assertStatus(403);
        });
    });

    describe('Get Conversation Status', function () {
        test('returns conversation status', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'active',
                'turn_count' => 2,
                'total_tokens' => 150,
            ]);

            $response = $this->getJson("/api/v1/conversations/{$conversation->id}/status");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'conversation_id',
                    'stats' => [
                        'turns',
                        'tokens',
                    ],
                ]);

            expect($response->json('status'))->toBe('processing'); // 'active' maps to 'processing' for CLI
            expect($response->json('stats.turns'))->toBe(2);
            expect($response->json('stats.tokens'))->toBe(150);
        });

        test('returns tool request when waiting for tool', function () {
            $toolRequest = [
                'id' => 'call_123',
                'name' => 'bash',
                'arguments' => ['command' => 'ls'],
            ];

            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'paused',
                'waiting_for' => 'tool_result',
                'pending_tool_request' => $toolRequest,
            ]);

            $response = $this->getJson("/api/v1/conversations/{$conversation->id}/status");

            $response->assertStatus(200)
                ->assertJsonStructure([
                    'status',
                    'conversation_id',
                    'tool_request',
                    'submit_url',
                ]);

            expect($response->json('tool_request'))->toBe($toolRequest);
        });
    });

    describe('Stop Conversation', function () {
        test('user can stop an active conversation', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'active',
            ]);

            $response = $this->postJson("/api/v1/conversations/{$conversation->id}/stop");

            $response->assertStatus(200)
                ->assertJson([
                    'status' => 'cancelled',
                    'conversation_id' => $conversation->id,
                ]);

            $conversation->refresh();
            expect($conversation->status)->toBe('cancelled');
            expect($conversation->completed_at)->not->toBeNull();
        });

        test('user can stop a paused conversation', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'paused',
            ]);

            $response = $this->postJson("/api/v1/conversations/{$conversation->id}/stop");

            $response->assertStatus(200)
                ->assertJson([
                    'status' => 'cancelled',
                    'conversation_id' => $conversation->id,
                ]);
        });

        test('stopping a completed conversation returns current status', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'completed',
            ]);

            $response = $this->postJson("/api/v1/conversations/{$conversation->id}/stop");

            $response->assertStatus(200)
                ->assertJson([
                    'status' => 'completed',
                    'message' => 'Conversation is not running',
                ]);
        });

        test('user cannot stop another users conversation', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);
            $conversation = Conversation::factory()->create([
                'user_id' => $otherUser->id,
                'agent_id' => $otherAgent->id,
                'status' => 'active',
            ]);

            $response = $this->postJson("/api/v1/conversations/{$conversation->id}/stop");

            $response->assertStatus(403);
        });
    });

    describe('Delete Conversation', function () {
        test('user can delete their conversation', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
            ]);

            $response = $this->deleteJson("/api/v1/conversations/{$conversation->id}");

            $response->assertStatus(204);
            expect(Conversation::find($conversation->id))->toBeNull();
        });

        test('user cannot delete another users conversation', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);
            $conversation = Conversation::factory()->create([
                'user_id' => $otherUser->id,
                'agent_id' => $otherAgent->id,
            ]);

            $response = $this->deleteJson("/api/v1/conversations/{$conversation->id}");

            $response->assertStatus(403);
            expect(Conversation::find($conversation->id))->not->toBeNull();
        });
    });

    describe('Conversation Model', function () {
        test('can add messages to conversation', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'messages' => [],
            ]);

            $message = [
                'role' => 'user',
                'content' => 'Hello',
            ];

            $conversation->addMessage($message);

            $conversation->refresh();
            expect($conversation->conversationMessages)->toHaveCount(1);
            expect($conversation->conversationMessages->first()->content)->toBe('Hello');
        });

        test('can check if waiting for tool', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'waiting_for' => 'tool_result',
            ]);

            expect($conversation->isWaitingForTool())->toBeTrue();

            $conversation->update(['waiting_for' => 'none']);
            expect($conversation->isWaitingForTool())->toBeFalse();
        });

        test('can check if active', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'active',
            ]);

            expect($conversation->isActive())->toBeTrue();

            $conversation->update(['status' => 'completed']);
            expect($conversation->isActive())->toBeFalse();
        });

        test('can mark as completed', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'active',
            ]);

            $conversation->markAsCompleted();

            $conversation->refresh();
            expect($conversation->status)->toBe('completed');
            expect($conversation->completed_at)->not->toBeNull();
        });

        test('can check if cancelled', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'cancelled',
            ]);

            expect($conversation->isCancelled())->toBeTrue();

            $conversation->update(['status' => 'active']);
            expect($conversation->isCancelled())->toBeFalse();
        });

        test('can mark as cancelled', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'status' => 'active',
            ]);

            $conversation->markAsCancelled();

            $conversation->refresh();
            expect($conversation->status)->toBe('cancelled');
            expect($conversation->completed_at)->not->toBeNull();
            expect($conversation->last_activity_at)->not->toBeNull();
        });

        test('can increment turn count', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'turn_count' => 0,
            ]);

            $conversation->incrementTurn();

            $conversation->refresh();
            expect($conversation->turn_count)->toBe(1);
        });

        test('can add tokens', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'total_tokens' => 100,
            ]);

            $conversation->addTokens(50);

            $conversation->refresh();
            expect($conversation->total_tokens)->toBe(150);
        });

        test('can add token usage with breakdown', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'total_tokens' => 0,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
            ]);

            $conversation->addTokenUsage(100, 50);

            $conversation->refresh();
            expect($conversation->prompt_tokens)->toBe(100)
                ->and($conversation->completion_tokens)->toBe(50)
                ->and($conversation->total_tokens)->toBe(150);
        });

        test('can get token usage', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'total_tokens' => 150,
                'prompt_tokens' => 100,
                'completion_tokens' => 50,
                'context_limit' => 4096,
            ]);

            $tokenUsage = $conversation->getTokenUsage();

            expect($tokenUsage->promptTokens)->toBe(100)
                ->and($tokenUsage->completionTokens)->toBe(50)
                ->and($tokenUsage->totalTokens)->toBe(150)
                ->and($tokenUsage->contextLimit)->toBe(4096);
        });

        test('can check if approaching context limit', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'total_tokens' => 3500,
                'context_limit' => 4096,
            ]);

            expect($conversation->isApproachingContextLimit(0.8))->toBeTrue();

            $conversation->update(['total_tokens' => 2000]);
            expect($conversation->isApproachingContextLimit(0.8))->toBeFalse();
        });

        test('can recalculate context usage from messages', function () {
            $conversation = Conversation::factory()->create([
                'user_id' => $this->user->id,
                'agent_id' => $this->agent->id,
                'estimated_context_usage' => 0,
            ]);

            // Create messages using the relational table
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'position' => 0,
                'role' => 'user',
                'content' => 'Hello',
                'token_count' => 10,
            ]);
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'position' => 1,
                'role' => 'assistant',
                'content' => 'Hi there!',
                'token_count' => 15,
            ]);
            Message::factory()->create([
                'conversation_id' => $conversation->id,
                'position' => 2,
                'role' => 'user',
                'content' => 'How are you?',
                'token_count' => 12,
            ]);

            $conversation->recalculateContextUsage();

            $conversation->refresh();
            expect($conversation->estimated_context_usage)->toBe(37);
        });
    });

});

describe('Conversation Management - Unauthenticated', function () {
    test('unauthenticated user cannot create conversation', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/conversations");

        $response->assertStatus(401);
    });

    test('unauthenticated user cannot list conversations', function () {
        $response = $this->getJson('/api/v1/conversations');

        $response->assertStatus(401);
    });

    test('unauthenticated user cannot view conversation', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'agent_id' => $agent->id,
        ]);

        $response = $this->getJson("/api/v1/conversations/{$conversation->id}");

        $response->assertStatus(401);
    });
});
