<?php

namespace App\Jobs;

use App\Events\ExecutionStatusUpdated;
use App\Models\Execution;
use App\Services\AIBackendManager;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteAgentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct(public Execution $execution) {}

    /**
     * Execute the job.
     */
    public function handle(AIBackendManager $backendManager): void
    {
        // Update execution status to running
        $this->execution->update([
            'status' => 'running',
            'started_at' => Carbon::now(),
        ]);

        // Broadcast status update
        broadcast(new ExecutionStatusUpdated($this->execution->fresh()));

        $logs = [];

        try {
            // Load the agent and task
            $agent = $this->execution->task->agent;
            $task = $this->execution->task;

            $logs[] = sprintf('[%s] Execution started for agent: %s', Carbon::now()->toDateTimeString(), $agent->name);

            // Get the AI backend
            $backend = $backendManager->driver($agent->ai_backend);
            $logs[] = sprintf('[%s] Using AI backend: %s', Carbon::now()->toDateTimeString(), $agent->ai_backend);

            // Build the context for execution
            $context = [
                'task' => $task->payload,
                'agent_code' => $agent->code,
                'agent_config' => $agent->config,
                'tools' => $agent->tools->map(function ($tool) {
                    return [
                        'name' => $tool->name,
                        'type' => $tool->type,
                        'config' => $tool->config,
                    ];
                })->toArray(),
                'input_files' => $this->execution->files()
                    ->wherePivot('role', 'input')
                    ->get()
                    ->map(function ($file) {
                        return [
                            'id' => $file->id,
                            'path' => $file->path,
                            'type' => $file->type,
                            'mime_type' => $file->mime_type,
                        ];
                    })->toArray(),
            ];

            $logs[] = sprintf('[%s] Executing agent with context...', Carbon::now()->toDateTimeString());

            // Execute the agent
            $response = $backend->execute($agent, $context);

            $logs[] = sprintf('[%s] Execution completed successfully', Carbon::now()->toDateTimeString());
            $logs[] = sprintf('[%s] Tokens used: %d', Carbon::now()->toDateTimeString(), $response->tokensUsed);
            $logs[] = sprintf('[%s] Finish reason: %s', Carbon::now()->toDateTimeString(), $response->finishReason);

            // Update execution with successful result
            $this->execution->update([
                'status' => 'completed',
                'completed_at' => Carbon::now(),
                'result' => [
                    'content' => $response->content,
                    'model' => $response->model,
                    'tokens_used' => $response->tokensUsed,
                    'finish_reason' => $response->finishReason,
                    'metadata' => $response->metadata,
                ],
                'logs' => implode("\n", $logs),
            ]);

            // Broadcast completion
            broadcast(new ExecutionStatusUpdated($this->execution->fresh()));
        } catch (\Exception $e) {
            $logs[] = sprintf('[%s] Execution failed: %s', Carbon::now()->toDateTimeString(), $e->getMessage());

            // Update execution with error
            $this->execution->update([
                'status' => 'failed',
                'completed_at' => Carbon::now(),
                'error' => $e->getMessage(),
                'logs' => implode("\n", $logs),
            ]);

            // Broadcast failure
            broadcast(new ExecutionStatusUpdated($this->execution->fresh()));

            // Re-throw the exception to trigger retry logic
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Update execution to failed if all retries exhausted
        $this->execution->update([
            'status' => 'failed',
            'completed_at' => Carbon::now(),
            'error' => sprintf('Job failed after %d attempts: %s', $this->tries, $exception->getMessage()),
        ]);

        // Broadcast final failure
        broadcast(new ExecutionStatusUpdated($this->execution->fresh()));
    }
}
