<?php

use App\Models\Agent;
use App\Models\Execution;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

describe('AgentController', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->agent = Agent::factory()->create(['user_id' => $this->user->id]);
        Sanctum::actingAs($this->user);
    });

    describe('executions', function () {
        test('returns executions for specific agent', function () {
            $task = Task::factory()->create(['agent_id' => $this->agent->id]);
            Execution::factory()->count(3)->create(['task_id' => $task->id]);

            // Create executions for another agent
            $otherAgent = Agent::factory()->create(['user_id' => $this->user->id]);
            $otherTask = Task::factory()->create(['agent_id' => $otherAgent->id]);
            Execution::factory()->count(2)->create(['task_id' => $otherTask->id]);

            $response = $this->getJson("/api/v1/agents/{$this->agent->id}/executions");

            $response->assertOk();
            expect($response->json('data'))->toHaveCount(3);
        });

        test('can filter agent executions by status', function () {
            $task = Task::factory()->create(['agent_id' => $this->agent->id]);
            Execution::factory()->create(['task_id' => $task->id, 'status' => 'completed']);
            Execution::factory()->create(['task_id' => $task->id, 'status' => 'completed']);
            Execution::factory()->create(['task_id' => $task->id, 'status' => 'pending']);

            $response = $this->getJson("/api/v1/agents/{$this->agent->id}/executions?status=completed");

            $response->assertOk();
            expect($response->json('data'))->toHaveCount(2);
            $statuses = collect($response->json('data'))->pluck('status')->unique()->toArray();
            expect($statuses)->toBe(['completed']);
        });

        test('paginates agent executions', function () {
            $task = Task::factory()->create(['agent_id' => $this->agent->id]);
            Execution::factory()->count(20)->create(['task_id' => $task->id]);

            $response = $this->getJson("/api/v1/agents/{$this->agent->id}/executions?per_page=5");

            $response->assertOk()
                ->assertJsonPath('per_page', 5)
                ->assertJsonPath('total', 20);
            expect($response->json('data'))->toHaveCount(5);
        });

        test('cannot view executions for another users agent', function () {
            $otherUser = User::factory()->create();
            $otherAgent = Agent::factory()->create(['user_id' => $otherUser->id]);

            $response = $this->getJson("/api/v1/agents/{$otherAgent->id}/executions");

            $response->assertForbidden();
        });

        test('includes task relationship in response', function () {
            $task = Task::factory()->create(['agent_id' => $this->agent->id]);
            Execution::factory()->create(['task_id' => $task->id]);

            $response = $this->getJson("/api/v1/agents/{$this->agent->id}/executions");

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'task_id', 'status', 'task'],
                    ],
                ]);
        });
    });
});
