<?php

use App\Models\Agent;
use App\Models\Execution;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('ExecutionController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create(['user_id' => $this->user->id]);
        $this->task = Task::factory()->create(['agent_id' => $this->agent->id]);
        Sanctum::actingAs($this->user);
    });

    describe('cancel', function () {
        test('can cancel pending execution', function () {
            $execution = Execution::factory()->create([
                'task_id' => $this->task->id,
                'status' => 'pending',
            ]);

            $response = $this->postJson("/api/v1/executions/{$execution->id}/cancel");

            $response->assertOk()
                ->assertJsonPath('message', 'Execution cancelled')
                ->assertJsonPath('execution.status', 'cancelled');

            $execution->refresh();
            expect($execution->status)->toBe('cancelled')
                ->and($execution->error)->toBe('Cancelled by user')
                ->and($execution->completed_at)->not->toBeNull();
        });

        test('can cancel running execution', function () {
            $execution = Execution::factory()->create([
                'task_id' => $this->task->id,
                'status' => 'running',
                'started_at' => now(),
            ]);

            $response = $this->postJson("/api/v1/executions/{$execution->id}/cancel");

            $response->assertOk()
                ->assertJsonPath('execution.status', 'cancelled');
        });

        test('cannot cancel completed execution', function () {
            $execution = Execution::factory()->create([
                'task_id' => $this->task->id,
                'status' => 'completed',
                'completed_at' => now(),
            ]);

            $response = $this->postJson("/api/v1/executions/{$execution->id}/cancel");

            $response->assertStatus(422)
                ->assertJsonPath('error', 'Cannot cancel execution with status: completed');
        });

        test('cannot cancel failed execution', function () {
            $execution = Execution::factory()->create([
                'task_id' => $this->task->id,
                'status' => 'failed',
                'completed_at' => now(),
            ]);

            $response = $this->postJson("/api/v1/executions/{$execution->id}/cancel");

            $response->assertStatus(422)
                ->assertJsonPath('error', 'Cannot cancel execution with status: failed');
        });

        test('cannot cancel another users execution', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);
            $otherTask = Task::factory()->create(['agent_id' => $otherAgent->id]);
            $execution = Execution::factory()->create([
                'task_id' => $otherTask->id,
                'status' => 'pending',
            ]);

            $response = $this->postJson("/api/v1/executions/{$execution->id}/cancel");

            $response->assertForbidden();
        });
    });

    describe('index', function () {
        test('can filter executions by status', function () {
            Execution::factory()->create([
                'task_id' => $this->task->id,
                'status' => 'completed',
            ]);
            Execution::factory()->create([
                'task_id' => $this->task->id,
                'status' => 'pending',
            ]);

            $response = $this->getJson('/api/v1/executions?status=completed');

            $response->assertOk();
            $statuses = collect($response->json('data'))->pluck('status')->unique()->toArray();
            expect($statuses)->toBe(['completed']);
        });
    });
});
