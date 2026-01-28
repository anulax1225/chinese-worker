<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('Conversation SSE Stream', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create([
            'user_id' => $this->user->id,
            'ai_backend' => 'ollama',
        ]);
        $this->conversation = Conversation::factory()->create([
            'user_id' => $this->user->id,
            'agent_id' => $this->agent->id,
        ]);
        Sanctum::actingAs($this->user);
    });

    test('stream endpoint returns correct headers for SSE', function () {
        $response = $this->get("/api/v1/conversations/{$this->conversation->id}/stream");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8');
        $response->assertHeader('Cache-Control', 'no-cache, private');
        $response->assertHeader('X-Accel-Buffering', 'no');
    });

    test('user cannot access stream for another users conversation', function () {
        $otherUser = User::factory()->create();
        $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);
        $otherConversation = Conversation::factory()->create([
            'user_id' => $otherUser->id,
            'agent_id' => $otherAgent->id,
        ]);

        $response = $this->get("/api/v1/conversations/{$otherConversation->id}/stream");

        $response->assertStatus(403);
    });

    test('stream endpoint returns 404 for non-existent conversation', function () {
        $response = $this->get('/api/v1/conversations/999999/stream');

        $response->assertStatus(404);
    });
});

describe('Conversation SSE Stream - Unauthenticated', function () {
    test('unauthenticated user cannot access stream', function () {
        $user = User::factory()->create();
        $agent = Agent::factory()->create(['user_id' => $user->id]);
        $conversation = Conversation::factory()->create([
            'user_id' => $user->id,
            'agent_id' => $agent->id,
        ]);

        // Sanctum middleware redirects unauthenticated web requests
        $response = $this->get("/api/v1/conversations/{$conversation->id}/stream");

        $response->assertStatus(302);
    });
});
