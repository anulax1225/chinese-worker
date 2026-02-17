<?php

namespace App\Jobs;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessageEmbedding;
use App\Services\RAG\EmbeddingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    public function handle(EmbeddingService $embeddingService): void
    {
        // Skip if RAG is disabled
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
            // Get messages that need embedding
            $messages = $this->getMessagesToEmbed($conversation);

            if ($messages->isEmpty()) {
                Log::info('[EmbedConversationMessagesJob] No messages need embedding', [
                    'conversation_id' => $conversation->id,
                ]);

                return;
            }

            // Process in batches
            $batchSize = config('ai.rag.embedding_batch_size', 100);
            $batches = $messages->chunk($batchSize);

            $totalEmbedded = 0;

            foreach ($batches as $batch) {
                $this->embedMessageBatch($batch, $embeddingService);
                $totalEmbedded += $batch->count();

                Log::debug('[EmbedConversationMessagesJob] Embedded batch', [
                    'conversation_id' => $conversation->id,
                    'batch_size' => $batch->count(),
                    'total_embedded' => $totalEmbedded,
                    'remaining' => $messages->count() - $totalEmbedded,
                ]);
            }

            $duration = microtime(true) - $startTime;
            Log::info('[EmbedConversationMessagesJob] Completed', [
                'conversation_id' => $conversation->id,
                'messages_embedded' => $totalEmbedded,
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
            ->whereIn('role', ['user', 'assistant']) // Only embed user/assistant messages
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
     * Embed a batch of messages.
     *
     * @param  Collection<int, Message>  $messages
     */
    protected function embedMessageBatch(Collection $messages, EmbeddingService $embeddingService): void
    {
        $model = $this->model ?? config('ai.rag.embedding_model');

        // Prepare texts for embedding
        $texts = $messages->map(fn (Message $m) => $this->prepareMessageText($m))->toArray();

        // Generate embeddings
        $embeddings = $embeddingService->embedBatch($texts, $model);

        // Store embeddings
        foreach ($messages as $index => $message) {
            $embeddingArray = $embeddings[$index];
            $text = $texts[$index];

            $this->storeMessageEmbedding($message, $embeddingArray, $text, $model, $embeddingService);
        }
    }

    /**
     * Prepare message text for embedding.
     */
    protected function prepareMessageText(Message $message): string
    {
        $text = $message->content ?? '';

        // Include role context for better semantic understanding
        $rolePrefix = match ($message->role) {
            'user' => 'User: ',
            'assistant' => 'Assistant: ',
            default => '',
        };

        return $rolePrefix.$text;
    }

    /**
     * Store embedding for a message.
     *
     * @param  array<float>  $embeddingArray
     */
    protected function storeMessageEmbedding(
        Message $message,
        array $embeddingArray,
        string $text,
        string $model,
        EmbeddingService $embeddingService
    ): void {
        $sparseVector = $embeddingService->generateSparseEmbedding($text);
        $contentHash = MessageEmbedding::hashContent($text);

        // Create or update embedding record
        $embedding = MessageEmbedding::updateOrCreate(
            ['message_id' => $message->id],
            [
                'conversation_id' => $message->conversation_id,
                'embedding_raw' => $embeddingArray,
                'embedding_model' => $model,
                'embedding_dimensions' => \count($embeddingArray),
                'embedding_generated_at' => now(),
                'sparse_vector' => $sparseVector,
                'content_hash' => $contentHash,
                'token_count' => $message->token_count,
            ]
        );

        // Update pgvector column directly (skip when dimensions don't match)
        if ($this->usesPgvector() && \count($embeddingArray) === 1536) {
            $embeddingString = '['.implode(',', $embeddingArray).']';
            DB::statement(
                'UPDATE message_embeddings SET embedding = ?::vector WHERE id = ?',
                [$embeddingString, $embedding->id]
            );
        }
    }

    /**
     * Check if we're using PostgreSQL with pgvector.
     */
    protected function usesPgvector(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
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
