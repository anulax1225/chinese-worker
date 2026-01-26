<?php

namespace App\Services;

use App\Jobs\ExecuteAgentJob;
use App\Models\Agent;
use App\Models\Execution;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ExecutionService
{
    /**
     * Execute an agent with the given payload.
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
