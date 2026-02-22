<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Services\AgenticLoop;
use App\Services\ConversationEventBroadcaster;
use App\Services\Runtime\DatabaseRuntime;
use Exception;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessConversationTurn implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     * Set high to allow for long AI responses.
     */
    public int $timeout = 12000;

    /**
     * The number of times the job may be attempted.
     * AI calls should not be retried automatically.
     */
    public int $tries = 1;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    public function __construct(
        public Conversation $conversation
    ) {}

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'conversation:'.$this->conversation->id,
            'user:'.$this->conversation->user_id,
            'agent:'.$this->conversation->agent_id,
        ];
    }

    public function handle(AgenticLoop $loop): void
    {
        // Check if cancelled before starting
        $this->conversation->refresh();
        if ($this->conversation->isCancelled()) {
            Log::info('Job skipped - conversation cancelled', [
                'conversation_id' => $this->conversation->id,
            ]);

            return;
        }

        // Eager load relationships to prevent N+1 queries
        $this->conversation->load(['agent']);

        $runtime = new DatabaseRuntime($this->conversation);
        $broadcaster = app(ConversationEventBroadcaster::class);

        try {
            $result = $loop->runSingleTurn(
                $runtime,
                onChunk: fn (string $chunk, string $type) => $broadcaster->textChunk($this->conversation, $chunk, $type),
                onToolExecuting: fn (array $tc) => $broadcaster->toolExecuting($this->conversation, $tc),
                onToolCompleted: fn (string $callId, string $name, bool $success, string $content) => $broadcaster->toolCompleted($this->conversation, $callId, $name, $success, $content),
                onToolRequest: fn (array $tr) => $broadcaster->toolRequest($this->conversation, $tr),
            );

            match ($result->status) {
                'completed' => $broadcaster->completed($this->conversation),
                'waiting_for_tool' => null, // Already broadcast via onToolRequest callback
                'continue' => self::dispatch($this->conversation), // System tools done, next turn
                'failed' => $broadcaster->failed($this->conversation, $result->error ?? 'Unknown error'),
                'cancelled', 'max_turns' => null, // Silent return, matching original behavior
            };
        } catch (Exception $e) {
            Log::error('Conversation turn failed', [
                'conversation_id' => $this->conversation->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->conversation->update(['status' => 'failed']);
            $broadcaster->failed($this->conversation, $e->getMessage());
        } finally {
            try {
                $broadcaster->disconnect();
            } catch (Throwable $e) {
                Log::warning('Broadcaster disconnect failed', [
                    'conversation_id' => $this->conversation->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Release database connection and force garbage collection
            DB::disconnect();
            gc_collect_cycles();
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('Conversation turn job failed', [
            'conversation_id' => $this->conversation->id,
            'error' => $exception?->getMessage(),
        ]);

        $this->conversation->update(['status' => 'failed']);
        app(ConversationEventBroadcaster::class)->failed(
            $this->conversation,
            $exception?->getMessage() ?? 'Unknown error'
        );
    }
}
