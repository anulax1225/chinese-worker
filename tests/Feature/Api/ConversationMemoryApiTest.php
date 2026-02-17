<?php

use App\Jobs\EmbedConversationMessagesJob;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEmbedding;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

describe('Memory API - POST embed', function () {
    test('dispatches embedding job when RAG is enabled', function () {
        config(['ai.rag.enabled' => true]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        // Add some messages
        Message::factory()->for($conversation)->count(3)->create([
            'role' => 'user',
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/embed");

        $response->assertStatus(202);
        $response->assertJsonPath('pending_count', 3);

        Queue::assertPushed(EmbedConversationMessagesJob::class);
    });

    test('returns error when RAG is disabled', function () {
        config(['ai.rag.enabled' => false]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/embed");

        $response->assertStatus(400);
        $response->assertJsonPath('error', 'RAG is not enabled');
    });

    test('returns message when all embeddings exist', function () {
        config(['ai.rag.enabled' => true]);

        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        // Add messages with embeddings
        $message = Message::factory()->for($conversation)->create(['role' => 'user']);
        MessageEmbedding::factory()->create([
            'message_id' => $message->id,
            'conversation_id' => $conversation->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/embed");

        $response->assertSuccessful();
        $response->assertJsonPath('pending_count', 0);
    });

    test('requires authorization for conversation', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $conversation = Conversation::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/embed");

        $response->assertForbidden();
    });
});

describe('Memory API - GET status', function () {
    test('returns embedding status for conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        // Add 5 messages
        Message::factory()->for($conversation)->count(5)->create(['role' => 'user']);

        // Embed 3 of them
        $messages = $conversation->conversationMessages()->take(3)->get();
        foreach ($messages as $message) {
            MessageEmbedding::factory()->create([
                'message_id' => $message->id,
                'conversation_id' => $conversation->id,
            ]);
        }

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/memory/status");

        $response->assertSuccessful();
        $response->assertJsonPath('total_messages', 5);
        $response->assertJsonPath('embedded_count', 3);
        $response->assertJsonPath('pending_count', 2);
        $response->assertJsonPath('completion_percentage', 60);
    });

    test('returns 100% completion when no messages', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/memory/status");

        $response->assertSuccessful();
        $response->assertJsonPath('total_messages', 0);
        $response->assertJsonPath('completion_percentage', 100);
    });
});

describe('Memory API - POST recall', function () {
    test('validates query is required', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/recall", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('query');
    });

    test('validates query min length', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/recall", [
                'query' => '',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('query');
    });

    test('validates threshold is between 0 and 1', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/memory/recall", [
                'query' => 'test query',
                'threshold' => 1.5,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('threshold');
    });

    test('requires authentication', function () {
        $conversation = Conversation::factory()->create();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/memory/recall", [
            'query' => 'test',
        ]);

        $response->assertUnauthorized();
    });
});
