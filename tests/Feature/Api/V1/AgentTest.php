<?php

use App\Models\Agent;
use App\Models\User;

describe('Agent Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    });

    describe('List Agents', function () {
        test('user can list their own agents', function () {
            Agent::factory()->count(3)->create(['user_id' => $this->user->id]);
            Agent::factory()->count(2)->create(); // Other user's agents

            $response = $this->getJson('/api/v1/agents');

            $response->assertStatus(200)
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'name',
                            'description',
                            'config',
                            'status',
                            'ai_backend',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ]);
        });

    });

    describe('Create Agent', function () {
        test('user can create an agent with valid data', function () {
            $agentData = [
                'name' => 'Test Agent',
                'description' => 'A test agent',
                'config' => ['max_iterations' => 5],
                'status' => 'active',
                'ai_backend' => 'ollama',
            ];

            $response = $this->postJson('/api/v1/agents', $agentData);

            $response->assertStatus(201)
                ->assertJsonFragment([
                    'name' => 'Test Agent',
                    'description' => 'A test agent',
                    'status' => 'active',
                ]);

            $this->assertDatabaseHas('agents', [
                'name' => 'Test Agent',
                'user_id' => $this->user->id,
            ]);
        });

        test('agent creation fails without required name', function () {
            $response = $this->postJson('/api/v1/agents', []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['name']);
        });

        test('agent creation fails with invalid status', function () {
            $response = $this->postJson('/api/v1/agents', [
                'name' => 'Test Agent',
                'status' => 'invalid_status',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
        });

        test('agent creation fails with invalid ai_backend', function () {
            $response = $this->postJson('/api/v1/agents', [
                'name' => 'Test Agent',
                'ai_backend' => 'invalid_backend',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['ai_backend']);
        });

    });

    describe('Show Agent', function () {
        test('user can view their own agent', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->getJson("/api/v1/agents/{$agent->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'id' => $agent->id,
                    'name' => $agent->name,
                ]);
        });

        test('user cannot view another user\'s agent', function () {
            $otherAgent = Agent::factory()->create();

            $response = $this->getJson("/api/v1/agents/{$otherAgent->id}");

            $response->assertStatus(403);
        });

        test('returns 404 for non-existent agent', function () {
            $response = $this->getJson('/api/v1/agents/99999');

            $response->assertStatus(404);
        });
    });

    describe('Update Agent', function () {
        test('user can update their own agent', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->putJson("/api/v1/agents/{$agent->id}", [
                'name' => 'Updated Agent',
                'description' => 'Updated description',
                'status' => 'inactive',
            ]);

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'name' => 'Updated Agent',
                    'status' => 'inactive',
                ]);

            $this->assertDatabaseHas('agents', [
                'id' => $agent->id,
                'name' => 'Updated Agent',
            ]);
        });

        test('user cannot update another user\'s agent', function () {
            $otherAgent = Agent::factory()->create();

            $response = $this->putJson("/api/v1/agents/{$otherAgent->id}", [
                'name' => 'Updated Agent',
            ]);

            $response->assertStatus(403);
        });

        test('agent update fails with invalid data', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->putJson("/api/v1/agents/{$agent->id}", [
                'status' => 'invalid_status',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['status']);
        });
    });

    describe('Delete Agent', function () {
        test('user can delete their own agent', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->deleteJson("/api/v1/agents/{$agent->id}");

            $response->assertStatus(204);

            $this->assertDatabaseMissing('agents', [
                'id' => $agent->id,
            ]);
        });

        test('user cannot delete another user\'s agent', function () {
            $otherAgent = Agent::factory()->create();

            $response = $this->deleteJson("/api/v1/agents/{$otherAgent->id}");

            $response->assertStatus(403);
        });
    });

});

describe('Agent Management - Unauthenticated', function () {
    test('unauthenticated user cannot list agents', function () {
        $response = $this->getJson('/api/v1/agents');
        $response->assertStatus(401);
    });
});

describe('Agent Generate', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    });

    test('user can generate text using their agent', function () {
        $agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", [
            'prompt' => 'Why is the sky blue?',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'content',
                'model',
                'done',
            ])
            ->assertJson([
                'done' => true,
            ]);
    });

    test('generate requires a prompt', function () {
        $agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['prompt']);
    });

    test('user cannot generate using another user\'s agent', function () {
        $otherAgent = Agent::factory()->create(['ai_backend' => 'fake']);

        $response = $this->postJson("/api/v1/agents/{$otherAgent->id}/generate", [
            'prompt' => 'Test prompt',
        ]);

        $response->assertStatus(403);
    });

    test('generate accepts optional parameters', function () {
        $agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", [
            'prompt' => 'Explain quantum physics',
            'system' => 'You are a physics expert.',
            'temperature' => 0.7,
            'max_tokens' => 500,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'content',
                'model',
                'done',
            ]);
    });

    test('generate with thinking mode returns thinking content', function () {
        $agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", [
            'prompt' => 'Solve: 2+2*2',
            'think' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'content',
                'model',
                'done',
                'thinking',
            ]);
    });

    test('generate validates temperature range', function () {
        $agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", [
            'prompt' => 'Test',
            'temperature' => 3.0,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['temperature']);
    });

    test('generate validates top_p range', function () {
        $agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'fake',
        ]);

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", [
            'prompt' => 'Test',
            'top_p' => 1.5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['top_p']);
    });

    test('unauthenticated user cannot generate', function () {
        $agent = Agent::factory()->create(['ai_backend' => 'fake']);

        $this->app['auth']->forgetGuards();

        $response = $this->postJson("/api/v1/agents/{$agent->id}/generate", [
            'prompt' => 'Test prompt',
        ]);

        $response->assertStatus(401);
    });
});
