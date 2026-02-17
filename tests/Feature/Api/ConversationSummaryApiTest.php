<?php

use App\Enums\SummaryStatus;
use App\Jobs\CreateConversationSummaryJob;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

describe('Summary API - POST create', function () {
    test('creates pending summary and dispatches job', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/summaries", [
                'from_position' => 1,
                'to_position' => 50,
            ]);

        $response->assertStatus(202);
        $response->assertJsonPath('status', 'pending');
        $response->assertJsonPath('conversation_id', $conversation->id);

        Queue::assertPushed(CreateConversationSummaryJob::class);

        $this->assertDatabaseHas('conversation_summaries', [
            'conversation_id' => $conversation->id,
            'status' => 'pending',
        ]);
    });

    test('creates summary with default positions when not provided', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/summaries");

        $response->assertStatus(202);
        Queue::assertPushed(CreateConversationSummaryJob::class);
    });

    test('validates from_position is integer', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/summaries", [
                'from_position' => 'invalid',
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('from_position');
    });

    test('validates to_position is greater than from_position', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/summaries", [
                'from_position' => 50,
                'to_position' => 10,
            ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('to_position');
    });

    test('requires authentication', function () {
        $conversation = Conversation::factory()->create();

        $response = $this->postJson("/api/v1/conversations/{$conversation->id}/summaries");

        $response->assertUnauthorized();
    });

    test('requires authorization for conversation', function () {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $conversation = Conversation::factory()->for($otherUser)->create();

        $response = $this->actingAs($user)
            ->postJson("/api/v1/conversations/{$conversation->id}/summaries");

        $response->assertForbidden();
    });
});

describe('Summary API - GET list', function () {
    test('lists summaries for conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        ConversationSummary::factory()->for($conversation)->count(3)->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/summaries");

        $response->assertSuccessful();
        $response->assertJsonCount(3);
    });

    test('returns summaries with status field', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        ConversationSummary::factory()->for($conversation)->create([
            'status' => SummaryStatus::Completed,
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/summaries");

        $response->assertSuccessful();
        $response->assertJsonPath('0.status', 'completed');
    });

    test('does not include content for pending summaries', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();

        ConversationSummary::factory()->for($conversation)->pending()->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/summaries");

        $response->assertSuccessful();
        $response->assertJsonPath('0.status', 'pending');
        $response->assertJsonMissing(['content']);
    });
});

describe('Summary API - GET show', function () {
    test('shows single summary', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $summary = ConversationSummary::factory()->for($conversation)->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/summaries/{$summary->id}");

        $response->assertSuccessful();
        $response->assertJsonPath('id', $summary->id);
    });

    test('returns 404 for summary from different conversation', function () {
        $user = User::factory()->create();
        $conversation = Conversation::factory()->for($user)->create();
        $otherConversation = Conversation::factory()->for($user)->create();
        $summary = ConversationSummary::factory()->for($otherConversation)->create();

        $response = $this->actingAs($user)
            ->getJson("/api/v1/conversations/{$conversation->id}/summaries/{$summary->id}");

        $response->assertNotFound();
    });
});
