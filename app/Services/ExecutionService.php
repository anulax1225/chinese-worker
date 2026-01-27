<?php

namespace App\Services;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\Execution;
use App\Services\AgentLoop\AgentLoopService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ExecutionService
{
    public function __construct(
        protected AIBackendManager $aiBackendManager,
        protected AgentLoopService $agentLoopService
    ) {}

    /**
     * Execute an agent with the given payload (queued execution).
     */
    public function execute(Agent $agent, array $payload, array $fileIds = []): Execution
    {
        return DB::transaction(function () use ($agent, $payload, $fileIds) {
            // Create the task
            $task = $agent->tasks()->create([
                'payload' => $payload,
                'priority' => $payload['priority'] ?? 0,
                'scheduled_at' => $payload['scheduled_at'] ?? null,
            ]);

            // Create the execution
            $execution = $task->executions()->create([
                'status' => 'pending',
            ]);

            // Attach input files if provided
            if (! empty($fileIds)) {
                $execution->files()->attach($fileIds, ['role' => 'input']);
            }

            // Dispatch the execution job
            if ($task->scheduled_at) {
                // Schedule for later execution
                ExecuteAgentJob::dispatch($execution)->delay($task->scheduled_at);
            } else {
                // Execute immediately
                ExecuteAgentJob::dispatch($execution);
            }

            return $execution->load('files');
        });
    }

    /**
     * Execute an agent with streaming response (immediate, with agentic loop).
     */
    public function streamExecute(Agent $agent, array $payload, array $fileIds, callable $callback): Execution
    {
        return DB::transaction(function () use ($agent, $payload, $fileIds, $callback) {
            // Create the task
            $task = $agent->tasks()->create([
                'payload' => $payload,
                'priority' => $payload['priority'] ?? 0,
                'scheduled_at' => null, // Streaming executions are always immediate
            ]);

            // Create the execution
            $execution = $task->executions()->create([
                'status' => 'running',
                'started_at' => now(),
            ]);

            // Attach input files if provided
            if (! empty($fileIds)) {
                $execution->files()->attach($fileIds, ['role' => 'input']);
            }

            try {
                // Prepare context for agentic loop
                $context = [
                    'input' => $payload['payload']['input'] ?? '',
                    'parameters' => $payload['payload']['parameters'] ?? [],
                    'messages' => $payload['payload']['messages'] ?? [],
                    'images' => $payload['payload']['images'] ?? null,
                    'agentic' => $payload['payload']['agentic'] ?? true,
                    'max_turns' => $payload['payload']['max_turns'] ?? config('agent.max_turns', 25),
                ];

                // Check if agentic mode is enabled
                if ($context['agentic']) {
                    // Use the agentic loop service
                    $result = $this->agentLoopService->execute($agent, $context, $callback);

                    $status = $result->isCompleted() ? 'completed' : 'failed';
                    $execution->update([
                        'status' => $status,
                        'completed_at' => now(),
                        'result' => $result->toArray(),
                        'error' => $result->error,
                        'logs' => $this->buildLogsFromResult($result),
                    ]);
                } else {
                    // Legacy non-agentic execution
                    $backend = $this->aiBackendManager->driver($agent->ai_backend);
                    $response = $backend->streamExecute($agent, $context, $callback);

                    $execution->update([
                        'status' => 'completed',
                        'completed_at' => now(),
                        'result' => [
                            'content' => $response->content,
                            'model' => $response->model,
                            'tokens_used' => $response->tokensUsed,
                            'finish_reason' => $response->finishReason,
                        ],
                        'logs' => 'Execution completed successfully at '.now(),
                    ]);
                }
            } catch (\Exception $e) {
                // Update execution with error
                $execution->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error' => $e->getMessage(),
                    'logs' => 'Execution failed: '.$e->getMessage(),
                ]);
            }

            return $execution->fresh();
        });
    }

    /**
     * Execute an agent with agentic loop (non-streaming).
     */
    public function agenticExecute(Agent $agent, array $payload, array $fileIds = []): Execution
    {
        return DB::transaction(function () use ($agent, $payload, $fileIds) {
            // Create the task
            $task = $agent->tasks()->create([
                'payload' => $payload,
                'priority' => $payload['priority'] ?? 0,
                'scheduled_at' => null,
            ]);

            // Create the execution
            $execution = $task->executions()->create([
                'status' => 'running',
                'started_at' => now(),
            ]);

            // Attach input files if provided
            if (! empty($fileIds)) {
                $execution->files()->attach($fileIds, ['role' => 'input']);
            }

            try {
                // Prepare context for agentic loop
                $context = [
                    'input' => $payload['payload']['input'] ?? '',
                    'parameters' => $payload['payload']['parameters'] ?? [],
                    'messages' => $payload['payload']['messages'] ?? [],
                    'images' => $payload['payload']['images'] ?? null,
                    'max_turns' => $payload['payload']['max_turns'] ?? config('agent.max_turns', 25),
                ];

                // Execute with agentic loop
                $result = $this->agentLoopService->execute($agent, $context);

                $status = $result->isCompleted() ? 'completed' : 'failed';
                $execution->update([
                    'status' => $status,
                    'completed_at' => now(),
                    'result' => $result->toArray(),
                    'error' => $result->error,
                    'logs' => $this->buildLogsFromResult($result),
                ]);
            } catch (\Exception $e) {
                $execution->update([
                    'status' => 'failed',
                    'completed_at' => now(),
                    'error' => $e->getMessage(),
                    'logs' => 'Execution failed: '.$e->getMessage(),
                ]);
            }

            return $execution->fresh();
        });
    }

    /**
     * Build execution logs from an AgentLoopResult.
     */
    protected function buildLogsFromResult(\App\DTOs\AgentLoopResult $result): string
    {
        $logs = [];
        $logs[] = sprintf('[%s] Agentic execution started', now()->toDateTimeString());
        $logs[] = sprintf('[%s] Status: %s', now()->toDateTimeString(), $result->status);
        $logs[] = sprintf('[%s] Turns used: %d', now()->toDateTimeString(), $result->turnsUsed);

        if (! empty($result->toolResults)) {
            $logs[] = sprintf('[%s] Tool calls executed: %d', now()->toDateTimeString(), count($result->toolResults));
            foreach ($result->toolResults as $toolResult) {
                $status = $toolResult['result']['success'] ? 'success' : 'failed';
                $logs[] = sprintf(
                    '  - Turn %d: %s (%s)',
                    $toolResult['turn'],
                    $toolResult['tool'],
                    $status
                );
            }
        }

        if ($result->error) {
            $logs[] = sprintf('[%s] Error: %s', now()->toDateTimeString(), $result->error);
        }

        $logs[] = sprintf('[%s] Execution completed', now()->toDateTimeString());

        return implode("\n", $logs);
    }

    /**
     * Get the status of an execution.
     */
    public function getStatus(Execution $execution): string
    {
        return $execution->status;
    }

    /**
     * Get the logs of an execution.
     */
    public function getLogs(Execution $execution): string
    {
        return $execution->logs ?? '';
    }

    /**
     * Get the output files of an execution.
     */
    public function getOutputs(Execution $execution): Collection
    {
        return $execution->files()
            ->wherePivot('role', 'output')
            ->get();
    }
}
