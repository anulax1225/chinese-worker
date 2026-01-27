<?php

use App\Events\ExecutionStatusUpdated;
use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\Execution;
use App\Models\File;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

describe('Execution Management', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    });

    describe('List Executions', function () {
        test('user can list executions for their agents', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            Execution::factory()->count(3)->create(['task_id' => $task->id]);

            // Create executions for another user
            $otherAgent = Agent::factory()->create();
            $otherTask = Task::factory()->create(['agent_id' => $otherAgent->id]);
            Execution::factory()->count(2)->create(['task_id' => $otherTask->id]);

            $response = $this->getJson('/api/v1/executions');

            $response->assertStatus(200)
                ->assertJsonCount(3, 'data')
                ->assertJsonStructure([
                    'data' => [
                        '*' => [
                            'id',
                            'task_id',
                            'status',
                            'started_at',
                            'completed_at',
                            'created_at',
                            'updated_at',
                        ],
                    ],
                ]);
        });

    });

    describe('Execute Agent', function () {
        test('user can execute their own agent', function () {
            Queue::fake();

            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/execute", [
                'payload' => [
                    'input' => 'Test input',
                    'parameters' => ['temperature' => 0.7],
                ],
            ]);

            $response->assertStatus(201)
                ->assertJsonStructure([
                    'id',
                    'task_id',
                    'status',
                    'created_at',
                ]);

            Queue::assertPushed(ExecuteAgentJob::class);

            $this->assertDatabaseHas('executions', [
                'id' => $response->json('id'),
                'status' => 'pending',
            ]);

            $this->assertDatabaseHas('tasks', [
                'agent_id' => $agent->id,
            ]);
        });

        test('user cannot execute another user\'s agent', function () {
            $otherAgent = Agent::factory()->create();

            $response = $this->postJson("/api/v1/agents/{$otherAgent->id}/execute", [
                'payload' => [
                    'input' => 'Test input',
                ],
            ]);

            $response->assertStatus(403);
        });

        test('execution fails without payload', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/execute", []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['payload']);
        });

        test('user can execute agent with file inputs', function () {
            Queue::fake();

            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $files = File::factory()->count(2)->create([
                'user_id' => $this->user->id,
                'type' => 'input',
            ]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/execute", [
                'payload' => ['input' => 'Test input'],
                'file_ids' => $files->pluck('id')->toArray(),
            ]);

            $response->assertStatus(201);

            $execution = Execution::find($response->json('id'));
            expect($execution->files)->toHaveCount(2);
        });

        test('user can schedule agent execution', function () {
            Queue::fake();

            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $scheduledTime = now()->addHour();

            $response = $this->postJson("/api/v1/agents/{$agent->id}/execute", [
                'payload' => ['input' => 'Test input'],
                'scheduled_at' => $scheduledTime->toISOString(),
            ]);

            $response->assertStatus(201);

            $task = Task::find($response->json('task_id'));
            expect($task->scheduled_at)->not()->toBeNull();

            Queue::assertPushed(ExecuteAgentJob::class);
        });

        test('execution fails with invalid priority', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/execute", [
                'payload' => ['input' => 'Test input'],
                'priority' => 100, // Max is 10
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['priority']);
        });
    });

    describe('Show Execution', function () {
        test('user can view execution for their agent', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create(['task_id' => $task->id]);

            $response = $this->getJson("/api/v1/executions/{$execution->id}");

            $response->assertStatus(200)
                ->assertJsonFragment([
                    'id' => $execution->id,
                    'status' => 'completed',
                ]);
        });

        test('user cannot view execution for another user\'s agent', function () {
            $otherAgent = Agent::factory()->create();
            $otherTask = Task::factory()->create(['agent_id' => $otherAgent->id]);
            $otherExecution = Execution::factory()->create(['task_id' => $otherTask->id]);

            $response = $this->getJson("/api/v1/executions/{$otherExecution->id}");

            $response->assertStatus(403);
        });

        test('returns 404 for non-existent execution', function () {
            $response = $this->getJson('/api/v1/executions/99999');

            $response->assertStatus(404);
        });
    });

    describe('Get Execution Logs', function () {
        test('user can retrieve logs for their execution', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create([
                'task_id' => $task->id,
                'logs' => 'Test log entry 1\nTest log entry 2',
            ]);

            $response = $this->getJson("/api/v1/executions/{$execution->id}/logs");

            $response->assertStatus(200)
                ->assertJson([
                    'logs' => 'Test log entry 1\nTest log entry 2',
                ]);
        });

        test('user cannot retrieve logs for another user\'s execution', function () {
            $otherAgent = Agent::factory()->create();
            $otherTask = Task::factory()->create(['agent_id' => $otherAgent->id]);
            $otherExecution = Execution::factory()->create(['task_id' => $otherTask->id]);

            $response = $this->getJson("/api/v1/executions/{$otherExecution->id}/logs");

            $response->assertStatus(403);
        });

        test('returns empty string for execution without logs', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->create([
                'task_id' => $task->id,
                'logs' => null,
            ]);

            $response = $this->getJson("/api/v1/executions/{$execution->id}/logs");

            $response->assertStatus(200)
                ->assertJson(['logs' => '']);
        });
    });

    describe('Get Execution Outputs', function () {
        test('user can retrieve output files for their execution', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create(['task_id' => $task->id]);

            $outputFiles = File::factory()->count(2)->output()->create([
                'user_id' => $this->user->id,
            ]);

            $execution->files()->attach($outputFiles->pluck('id'), ['role' => 'output']);

            $response = $this->getJson("/api/v1/executions/{$execution->id}/outputs");

            $response->assertStatus(200)
                ->assertJsonCount(2, 'outputs')
                ->assertJsonStructure([
                    'outputs' => [
                        '*' => [
                            'id',
                            'path',
                            'type',
                            'size',
                            'mime_type',
                            'created_at',
                        ],
                    ],
                ]);
        });

        test('user cannot retrieve outputs for another user\'s execution', function () {
            $otherAgent = Agent::factory()->create();
            $otherTask = Task::factory()->create(['agent_id' => $otherAgent->id]);
            $otherExecution = Execution::factory()->create(['task_id' => $otherTask->id]);

            $response = $this->getJson("/api/v1/executions/{$otherExecution->id}/outputs");

            $response->assertStatus(403);
        });

        test('returns empty array for execution without outputs', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->create(['task_id' => $task->id]);

            $response = $this->getJson("/api/v1/executions/{$execution->id}/outputs");

            $response->assertStatus(200)
                ->assertJsonCount(0, 'outputs');
        });
    });

    describe('Execution Status Flow', function () {
        test('execution starts with pending status', function () {
            Queue::fake();

            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/execute", [
                'payload' => ['input' => 'Test'],
            ]);

            $response->assertStatus(201);

            $execution = Execution::find($response->json('id'));
            expect($execution->status)->toBe('pending')
                ->and($execution->started_at)->toBeNull()
                ->and($execution->completed_at)->toBeNull();
        });

        test('completed execution has result data', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create(['task_id' => $task->id]);

            expect($execution->status)->toBe('completed')
                ->and($execution->result)->not()->toBeNull()
                ->and($execution->result)->toHaveKey('content')
                ->and($execution->result)->toHaveKey('model')
                ->and($execution->result)->toHaveKey('tokens_used')
                ->and($execution->started_at)->not()->toBeNull()
                ->and($execution->completed_at)->not()->toBeNull();
        });

        test('failed execution has error message', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->failed()->create(['task_id' => $task->id]);

            expect($execution->status)->toBe('failed')
                ->and($execution->error)->not()->toBeNull()
                ->and($execution->started_at)->not()->toBeNull()
                ->and($execution->completed_at)->not()->toBeNull();
        });
    });

    describe('Streaming Execution', function () {
        test('user can stream agent execution with SSE', function () {
            Queue::fake();

            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/stream", [
                'payload' => [
                    'input' => 'Test streaming input',
                    'parameters' => ['temperature' => 0.7],
                ],
            ]);

            $response->assertStatus(200)
                ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')
                ->assertHeader('Cache-Control', 'no-cache, private');
        });

        test('user cannot stream another user\'s agent', function () {
            $otherAgent = Agent::factory()->create();

            $response = $this->postJson("/api/v1/agents/{$otherAgent->id}/stream", [
                'payload' => [
                    'input' => 'Test input',
                ],
            ]);

            $response->assertStatus(403);
        });

        test('streaming execution fails without payload', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);

            $response = $this->postJson("/api/v1/agents/{$agent->id}/stream", []);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['payload']);
        });
    });

    describe('WebSocket Broadcasting', function () {
        beforeEach(function () {
            $this->user = User::factory()->create();
        });

        test('execution status update broadcasts event', function () {
            Event::fake();

            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->pending()->create(['task_id' => $task->id]);

            // Simulate execution job
            $job = new ExecuteAgentJob($execution);

            // Execute the job (this will fail because Ollama is not running, but that's ok)
            try {
                $job->handle(app(\App\Services\AgentLoop\AgentLoopService::class));
            } catch (\Exception $e) {
                // Expected to fail in test environment
            }

            // Verify that ExecutionStatusUpdated event was dispatched
            Event::assertDispatched(ExecutionStatusUpdated::class);
        });

        test('broadcast event contains execution data', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create(['task_id' => $task->id]);

            $event = new ExecutionStatusUpdated($execution);

            $broadcastData = $event->broadcastWith();

            expect($broadcastData)->toHaveKeys([
                'id',
                'task_id',
                'status',
                'started_at',
                'completed_at',
                'result',
                'error',
                'updated_at',
            ])
                ->and($broadcastData['id'])->toBe($execution->id)
                ->and($broadcastData['status'])->toBe('completed');
        });

        test('broadcast event uses correct channel for user', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create(['task_id' => $task->id]);

            $event = new ExecutionStatusUpdated($execution);

            $channels = $event->broadcastOn();

            expect($channels)->toHaveCount(1)
                ->and($channels[0]->name)->toBe('private-user.'.$this->user->id);
        });

        test('broadcast event has correct event name', function () {
            $agent = Agent::factory()->create(['user_id' => $this->user->id]);
            $task = Task::factory()->create(['agent_id' => $agent->id]);
            $execution = Execution::factory()->completed()->create(['task_id' => $task->id]);

            $event = new ExecutionStatusUpdated($execution);

            expect($event->broadcastAs())->toBe('execution.updated');
        });
    });
});

describe('Execution Management - Unauthenticated', function () {
    test('unauthenticated user cannot list executions', function () {
        $response = $this->getJson('/api/v1/executions');
        $response->assertStatus(401);
    });
});
