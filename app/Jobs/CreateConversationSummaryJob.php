<?php

namespace App\Jobs;

use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\Enums\SummaryStatus;
use App\Models\Conversation;
use App\Models\ConversationSummary;
use App\Services\AIBackendManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class CreateConversationSummaryJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

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
     * @param  int|null  $toPosition  Ending position (null = last message)
     */
    public function __construct(
        public ConversationSummary $summary,
        public ?int $fromPosition = null,
        public ?int $toPosition = null,
    ) {}

    /**
     * Calculate the number of seconds to wait before retrying the job.
     *
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return (string) $this->summary->id;
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'summarization',
            'summary:'.$this->summary->id,
            'conversation:'.$this->summary->conversation_id,
        ];
    }

    public function handle(AIBackendManager $backendManager, TokenEstimator $tokenEstimator): void
    {
        $summary = $this->summary;
        $conversation = $summary->conversation;

        Log::info('[CreateConversationSummaryJob] Starting', [
            'summary_id' => $summary->id,
            'conversation_id' => $conversation->id,
            'from_position' => $this->fromPosition,
            'to_position' => $this->toPosition,
        ]);

        // Mark as processing
        $summary->markAsProcessing();

        try {
            $startTime = hrtime(true);

            // Get messages to summarize
            $messages = $this->getMessagesToSummarize($conversation);

            if ($messages->isEmpty()) {
                $summary->markAsFailed('No messages found in the specified range');

                return;
            }

            $minMessages = config('ai.summarization.min_messages_for_summary', 5);
            if ($messages->count() < $minMessages) {
                $summary->markAsFailed("Insufficient messages: {$messages->count()} (minimum: {$minMessages})");

                return;
            }

            // Determine actual position range
            $fromPosition = $this->fromPosition ?? $messages->first()->position;
            $toPosition = $this->toPosition ?? $messages->last()->position;

            // Convert to ChatMessage DTOs
            $chatMessages = $messages->map(fn ($m) => $m->toChatMessage())->all();

            // Calculate original token count
            $originalTokens = 0;
            foreach ($chatMessages as $message) {
                $originalTokens += $message->tokenCount ?? $tokenEstimator->estimate($message);
            }

            // Build summarization prompt
            $prompt = $this->buildPrompt($chatMessages);

            // Call AI for summarization
            $summaryContent = $this->callAI($backendManager, $prompt, $conversation);

            if (empty(trim($summaryContent))) {
                $summary->markAsFailed('AI returned empty response');

                return;
            }

            // Calculate summary token count
            $summaryTokens = $tokenEstimator->estimate(ChatMessage::system($summaryContent));

            // Get message IDs
            $messageIds = $messages->pluck('id')->all();

            // Determine backend and model used
            $agent = $conversation->agent;
            $backendName = $agent?->ai_backend ?? config('ai.summarization.backend') ?? config('ai.default');
            $modelName = config("ai.backends.{$backendName}.model", 'unknown');

            $durationMs = (hrtime(true) - $startTime) / 1e6;

            // Mark as completed
            $summary->markAsCompleted([
                'from_position' => $fromPosition,
                'to_position' => $toPosition,
                'content' => $summaryContent,
                'token_count' => $summaryTokens,
                'backend_used' => $backendName,
                'model_used' => $modelName,
                'summarized_message_ids' => $messageIds,
                'original_token_count' => $originalTokens,
                'metadata' => [
                    'duration_ms' => $durationMs,
                    'message_count' => count($chatMessages),
                ],
            ]);

            Log::info('[CreateConversationSummaryJob] Completed', [
                'summary_id' => $summary->id,
                'conversation_id' => $conversation->id,
                'message_count' => count($chatMessages),
                'original_tokens' => $originalTokens,
                'summary_tokens' => $summaryTokens,
                'compression_ratio' => $summary->fresh()->getCompressionRatio(),
                'duration_ms' => $durationMs,
            ]);
        } catch (Throwable $e) {
            Log::error('[CreateConversationSummaryJob] Failed', [
                'summary_id' => $summary->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            $summary->markAsFailed($e->getMessage());

            throw $e;
        }
    }

    /**
     * Get messages to summarize based on position range.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \App\Models\Message>
     */
    protected function getMessagesToSummarize(Conversation $conversation)
    {
        $query = $conversation->conversationMessages()
            ->with(['toolCalls', 'attachments'])
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
     * Build the summarization prompt.
     *
     * @param  array<ChatMessage>  $messages
     */
    protected function buildPrompt(array $messages): string
    {
        $targetTokens = config('ai.summarization.target_tokens', 500);
        $customPrompt = config('ai.summarization.prompt');

        $instruction = $customPrompt ?? 'Summarize the following conversation. Preserve key topics, decisions, and context. Be concise.';
        $instruction .= "\n\nTarget length: approximately {$targetTokens} tokens.";

        $conversationText = $this->formatMessages($messages);

        return $instruction."\n\n---\n\n".$conversationText;
    }

    /**
     * Format messages into text for summarization.
     *
     * @param  array<ChatMessage>  $messages
     */
    protected function formatMessages(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = ucfirst($message->role);
            $content = $message->content;

            if (! empty($message->toolCalls)) {
                $toolNames = array_map(fn ($tc) => $tc['name'] ?? 'unknown', $message->toolCalls);
                $content .= ' [Called tools: '.implode(', ', $toolNames).']';
            }

            if ($message->role === 'tool') {
                $role = $message->name ? "Tool ({$message->name})" : 'Tool';
            }

            $lines[] = "{$role}: {$content}";
        }

        return implode("\n\n", $lines);
    }

    /**
     * Call AI backend for summarization.
     */
    protected function callAI(AIBackendManager $backendManager, string $prompt, Conversation $conversation): string
    {
        $agent = $conversation->agent;
        $backendName = $agent?->ai_backend ?? config('ai.summarization.backend') ?? config('ai.default');

        $systemPrompt = 'You are a summarization assistant. Your task is to create concise, accurate summaries of conversations.';

        $messages = [
            ChatMessage::system($systemPrompt),
            ChatMessage::user($prompt),
        ];

        if ($agent !== null) {
            $result = $backendManager->forAgent($agent);
            $backend = $result['backend'];

            $response = $backend->execute($agent, [
                'messages' => $messages,
                'tools' => [],
                'system_prompt' => $systemPrompt,
            ]);
        } else {
            $backend = $backendManager->driver($backendName);
            $tempAgent = \App\Models\Agent::factory()->make([
                'ai_backend' => $backendName,
                'model' => config("ai.backends.{$backendName}.model"),
            ]);

            $response = $backend->execute($tempAgent, [
                'messages' => $messages,
                'tools' => [],
                'system_prompt' => $systemPrompt,
            ]);
        }

        return $response->content;
    }

    /**
     * Handle a job failure.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('[CreateConversationSummaryJob] Job failed permanently', [
            'summary_id' => $this->summary->id,
            'conversation_id' => $this->summary->conversation_id,
            'error' => $exception?->getMessage(),
        ]);

        if ($this->summary->status !== SummaryStatus::Failed) {
            $this->summary->markAsFailed($exception?->getMessage() ?? 'Unknown error');
        }
    }
}
