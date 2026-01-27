<?php

namespace App\Jobs;

use App\Events\ExecutionStatusUpdated;
use App\Models\Execution;
use App\Services\AgentLoop\AgentLoopService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ExecuteAgentJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600; // 10 minutes for agentic loops

    /**
     * Create a new job instance.
     */
    public function __construct(public Execution $execution) {}

    /**
     * Execute the job.
     */
    public function handle(AgentLoopService $agentLoopService): void
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
            $logs[] = sprintf('[%s] Using AI backend: %s', Carbon::now()->toDateTimeString(), $agent->ai_backend);

            // Build the context for execution
            $taskPayload = $task->payload;
            $context = [
                'input' => $taskPayload['payload']['input'] ?? '',
                'parameters' => $taskPayload['payload']['parameters'] ?? [],
                'messages' => $taskPayload['payload']['messages'] ?? [],
                'images' => $taskPayload['payload']['images'] ?? null,
                'max_turns' => $taskPayload['payload']['max_turns'] ?? config('agent.max_turns', 25),
                'agentic' => $taskPayload['payload']['agentic'] ?? true,
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

            $logs[] = sprintf('[%s] Executing agent with agentic loop...', Carbon::now()->toDateTimeString());

            // Execute the agent with agentic loop
            $result = $agentLoopService->execute($agent, $context);

            $logs[] = sprintf('[%s] Agentic loop completed', Carbon::now()->toDateTimeString());
            $logs[] = sprintf('[%s] Status: %s', Carbon::now()->toDateTimeString(), $result->status);
            $logs[] = sprintf('[%s] Turns used: %d', Carbon::now()->toDateTimeString(), $result->turnsUsed);

            if (! empty($result->toolResults)) {
                $logs[] = sprintf('[%s] Tool calls executed: %d', Carbon::now()->toDateTimeString(), count($result->toolResults));
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

            // Determine final status
            $status = $result->isCompleted() ? 'completed' : 'failed';

            if ($result->error) {
                $logs[] = sprintf('[%s] Error: %s', Carbon::now()->toDateTimeString(), $result->error);
            }

            // Update execution with result
            $this->execution->update([
                'status' => $status,
                'completed_at' => Carbon::now(),
                'result' => $result->toArray(),
                'error' => $result->error,
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
