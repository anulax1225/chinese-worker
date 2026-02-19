<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Services\Embedding\Writers\MessageEmbeddingWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbedConversationMessagesJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 600;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * Indicate if the job should be marked as failed on timeout.
     */
    public bool $failOnTimeout = true;

    /**
     * @param  int|null  $fromPosition  Starting position (null = first message)
     * @param  int|null  $toPosition  Ending position (null = latest message)
     */
    public function __construct(
        public Conversation $conversation,
        public ?int $fromPosition = null,
        public ?int $toPosition = null,
        public ?string $model = null
    ) {}

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "conversation:{$this->conversation->id}";
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'embedding',
            'message-embedding',
            'conversation:'.$this->conversation->id,
            'user:'.$this->conversation->user_id,
        ];
    }

    public function handle(MessageEmbeddingWriter $writer): void
    {
        if (! config('ai.rag.enabled', false)) {
            Log::info('[EmbedConversationMessagesJob] Skipping - RAG is disabled', [
                'conversation_id' => $this->conversation->id,
            ]);

            return;
        }

        $conversation = $this->conversation;
        $startTime = microtime(true);

        Log::info('[EmbedConversationMessagesJob] Starting', [
            'conversation_id' => $conversation->id,
            'from_position' => $this->fromPosition,
            'to_position' => $this->toPosition,
            'model' => $this->model ?? config('ai.rag.embedding_model'),
        ]);

        try {
            $messages = $this->getMessagesToEmbed($conversation);

            if ($messages->isEmpty()) {
                Log::info('[EmbedConversationMessagesJob] No messages need embedding', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            $writer->write($messages, $this->model);

            $duration = microtime(true) - $startTime;
            Log::info('[EmbedConversationMessagesJob] Completed', [
                'conversation_id' => $conversation->id,
                'messages_embedded' => $messages->count(),
                'duration_seconds' => round($duration, 2),
            ]);
        } catch (Throwable $e) {
            Log::error('[EmbedConversationMessagesJob] Failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get messages that need embedding based on position range.
     *
     * @return Collection<int, Message>
     */
    protected function getMessagesToEmbed(Conversation $conversation): Collection
    {
        $query = $conversation->conversationMessages()
            ->whereIn('role', ['user', 'assistant'])
            ->whereDoesntHave('embedding', fn ($q) => $q->whereNotNull('embedding_generated_at'))
            ->orderBy('position');

        if ($this->fromPosition !== null) {
            $query->where('position', '>=', $this->fromPosition);
        }

        if ($this->toPosition !== null) {
            $query->where('position', '<=', $this->toPosition);
        }

        return $query->get();
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('[EmbedConversationMessagesJob] Job failed permanently', [
            'conversation_id' => $this->conversation->id,
            'error' => $exception?->getMessage(),
        ]);
    }
}
