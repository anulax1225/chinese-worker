<?php

use App\DTOs\NormalizedModelConfig;
use App\Models\Agent;
use App\Models\User;
use App\Services\AI\FakeBackend;
use App\Services\AIBackendManager;
use Laravel\Sanctum\Sanctum;

describe('Ghost Conversations', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);
        Sanctum::actingAs($this->user);

        // Mock AIBackendManager to return FakeBackend
        $fakeBackend = new FakeBackend(['model' => 'test-model']);
        $modelConfig = new NormalizedModelConfig(
            model: 'test-model',
            temperature: 0.7,
            maxTokens: 4096,
            contextLength: 4096,
            timeout: 120,
        );

        $this->mock(AIBackendManager::class, function ($mock) use ($fakeBackend, $modelConfig) {
            $mock->shouldReceive('forAgent')
                ->andReturn([
                    'backend' => $fakeBackend->withConfig($modelConfig),
                    'config' => $modelConfig,
                ]);
        });
    });

    describe('JSON Mode (send)', function () {
        test('returns AI response as JSON', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
            ]);

            $response->assertOk()
                ->assertJsonStructure([
                    'status',
                    'messages',
                    'tool_request',
                    'error',
                    'stats' => ['turns', 'tokens', 'prompt_tokens', 'completion_tokens'],
                ]);

            expect($response->json('status'))->toBe('completed');
            expect($response->json('messages'))->toBeArray()->not->toBeEmpty();
            expect($response->json('error'))->toBeNull();
        });

        test('does not create database records', function () {
            $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello ghost',
            ]);

            $this->assertDatabaseMissing('conversations', [
                'agent_id' => $this->agent->id,
            ]);
            $this->assertDatabaseMissing('messages', [
                'content' => 'Hello ghost',
            ]);
        });

        test('returns messages including user message and assistant response', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hi there',
            ]);

            $messages = $response->json('messages');

            // Should have at least the user message and the assistant response
            expect(count($messages))->toBeGreaterThanOrEqual(2);

            // First message should be the user message
            expect($messages[0]['role'])->toBe('user');
            expect($messages[0]['content'])->toBe('Hi there');

            // Last message should be from the assistant
            $last = end($messages);
            expect($last['role'])->toBe('assistant');
        });

        test('hydrates previous messages for multi-turn', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'messages' => [
                    ['role' => 'user', 'content' => 'What is 2+2?'],
                    ['role' => 'assistant', 'content' => '4'],
                ],
                'content' => 'And 3+3?',
            ]);

            $response->assertOk();

            $messages = $response->json('messages');

            // Should have the 2 previous messages + new user message + assistant response
            expect(count($messages))->toBeGreaterThanOrEqual(4);
            expect($messages[0]['role'])->toBe('user');
            expect($messages[0]['content'])->toBe('What is 2+2?');
            expect($messages[1]['role'])->toBe('assistant');
            expect($messages[1]['content'])->toBe('4');
            expect($messages[2]['role'])->toBe('user');
            expect($messages[2]['content'])->toBe('And 3+3?');
        });

        test('returns stats with token usage', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
            ]);

            $stats = $response->json('stats');

            expect($stats['turns'])->toBeGreaterThanOrEqual(1);
            expect($stats['tokens'])->toBeGreaterThanOrEqual(0);
            expect($stats['prompt_tokens'])->toBeGreaterThanOrEqual(0);
            expect($stats['completion_tokens'])->toBeGreaterThanOrEqual(0);
        });

        test('accepts max_turns parameter', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'max_turns' => 5,
            ]);

            $response->assertOk();
        });

        test('accepts client_tool_schemas parameter', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'client_tool_schemas' => [
                    [
                        'name' => 'my_tool',
                        'description' => 'A custom tool',
                        'parameters' => ['type' => 'object', 'properties' => []],
                    ],
                ],
            ]);

            $response->assertOk();
        });
    });

    describe('SSE Stream Mode', function () {
        test('returns streamed response with SSE headers', function () {
            $response = $this->post("/api/v1/agents/{$this->agent->id}/ghost/stream", [
                'content' => 'Hello',
            ], [
                'Accept' => 'text/event-stream',
            ]);

            $response->assertOk();
            $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
            expect($response->headers->get('Cache-Control'))->toContain('no-cache');
            $response->assertHeader('X-Accel-Buffering', 'no');
        });

        test('stream response contains expected SSE events', function () {
            $response = $this->post("/api/v1/agents/{$this->agent->id}/ghost/stream", [
                'content' => 'Hello',
            ], [
                'Accept' => 'text/event-stream',
            ]);

            $content = $response->streamedContent();

            // Should contain connected event
            expect($content)->toContain('event: connected');
            // Should contain a text_chunk event (FakeBackend sends one chunk)
            expect($content)->toContain('event: text_chunk');
            // Should contain completed event
            expect($content)->toContain('event: completed');
        });
    });

    describe('Validation', function () {
        test('content is required without tool_result', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['content']);
        });

        test('content can be omitted when tool_result is provided', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'messages' => [
                    ['role' => 'user', 'content' => 'Run something'],
                    ['role' => 'assistant', 'content' => 'I will run that for you.', 'tool_calls' => [
                        ['id' => 'call_1', 'name' => 'bash', 'arguments' => ['command' => 'ls']],
                    ]],
                ],
                'tool_result' => [
                    'call_id' => 'call_1',
                    'success' => true,
                    'output' => 'file1.txt',
                ],
            ]);

            // Should not get 422 for missing content
            expect($response->status())->not->toBe(422);
        });

        test('messages must be an array', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'messages' => 'not-an-array',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['messages']);
        });

        test('message role must be valid', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'messages' => [
                    ['role' => 'invalid', 'content' => 'Test'],
                ],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['messages.0.role']);
        });

        test('max_turns must be between 1 and 50', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'max_turns' => 0,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['max_turns']);

            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'max_turns' => 51,
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['max_turns']);
        });

        test('tool_result requires call_id', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'tool_result' => [
                    'success' => true,
                    'output' => 'result',
                ],
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['tool_result.call_id']);
        });

        test('client_tool_schemas items require name, description, and parameters', function () {
            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
                'client_tool_schemas' => [
                    ['name' => 'tool_without_description'],
                ],
            ]);

            $response->assertStatus(422);
        });
    });

    describe('Authorization', function () {
        test('unauthenticated user cannot access ghost route', function () {
            // Reset auth — act as guest
            $this->app['auth']->forgetGuards();

            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost", [
                'content' => 'Hello',
            ]);

            $response->assertStatus(401);
        });

        test('user cannot use ghost route with another users agent', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->postJson("/api/v1/agents/{$otherAgent->id}/ghost", [
                'content' => 'Hello',
            ]);

            $response->assertStatus(403);
        });

        test('unauthenticated user cannot access ghost stream route', function () {
            $this->app['auth']->forgetGuards();

            $response = $this->postJson("/api/v1/agents/{$this->agent->id}/ghost/stream", [
                'content' => 'Hello',
            ]);

            $response->assertStatus(401);
        });
    });
});
