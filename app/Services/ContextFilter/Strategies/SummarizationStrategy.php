<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\Strategies;

use App\Contracts\ContextFilterStrategy;
use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\DTOs\FilterResult;
use App\Events\ConversationSummarized;
use App\Exceptions\InvalidOptionsException;
use App\Exceptions\SummarizationException;
use App\Services\ContextFilter\Strategies\Concerns\PreservesToolCallChains;
use App\Services\ContextFilter\SummarizationService;
use Illuminate\Support\Facades\Log;
use Throwable;

class SummarizationStrategy implements ContextFilterStrategy
{
    use PreservesToolCallChains;

    private const DEFAULT_MIN_MESSAGES = 5;

    private const DEFAULT_TARGET_TOKENS = 500;

    public function __construct(
        private readonly TokenBudgetStrategy $tokenBudgetStrategy,
        private readonly SummarizationService $summarizationService,
        private readonly TokenEstimator $tokenEstimator,
    ) {}

    /**
     * Strategy identifier.
     */
    public function name(): string
    {
        return 'summarization';
    }

    /**
     * Validate strategy options.
     */
    public function validateOptions(array $options): void
    {
        if (isset($options['min_messages'])) {
            if (! is_int($options['min_messages']) || $options['min_messages'] < 1) {
                throw InvalidOptionsException::invalidValue(
                    option: 'min_messages',
                    reason: 'must be a positive integer',
                    strategy: $this->name(),
                    options: $options,
                );
            }
        }

        if (isset($options['target_tokens'])) {
            if (! is_int($options['target_tokens']) || $options['target_tokens'] < 50) {
                throw InvalidOptionsException::invalidValue(
                    option: 'target_tokens',
                    reason: 'must be an integer >= 50',
                    strategy: $this->name(),
                    options: $options,
                );
            }
        }

        // Validate inner TokenBudget options
        $this->tokenBudgetStrategy->validateOptions($options);
    }

    /**
     * Filter messages, summarizing old messages when appropriate.
     */
    public function filter(FilterContext $context): FilterResult
    {
        $startTime = hrtime(true);

        $messages = $context->messages;
        $minMessages = $context->options['min_messages'] ?? config('ai.summarization.min_messages_for_summary', self::DEFAULT_MIN_MESSAGES);

        // First, use TokenBudgetStrategy to identify what would be removed
        $tokenBudgetResult = $this->tokenBudgetStrategy->filter($context);

        // If nothing would be removed, return as-is
        if (count($tokenBudgetResult->removedMessageIds) === 0) {
            return FilterResult::noOp($messages, $this->name());
        }

        // Check if we have enough messages to summarize
        $messagesToRemove = $this->getMessagesToRemove($messages, $tokenBudgetResult->removedMessageIds);

        if (count($messagesToRemove) < $minMessages) {
            // Not enough messages to summarize, fall back to token budget
            Log::debug('[SummarizationStrategy] Not enough messages to summarize, using token_budget fallback', [
                'conversation_id' => $context->conversation?->id,
                'removable_count' => count($messagesToRemove),
                'min_required' => $minMessages,
            ]);

            return $this->wrapResult($tokenBudgetResult, $startTime);
        }

        // Check if summarization is enabled and we have a conversation
        if ($context->conversation === null) {
            Log::debug('[SummarizationStrategy] No conversation context, using token_budget fallback');

            return $this->wrapResult($tokenBudgetResult, $startTime);
        }

        if (! config('ai.summarization.enabled', true)) {
            Log::debug('[SummarizationStrategy] Summarization disabled, using token_budget fallback');

            return $this->wrapResult($tokenBudgetResult, $startTime);
        }

        // Attempt to summarize the removable messages
        try {
            $summary = $this->summarizationService->summarize(
                conversation: $context->conversation,
                messages: $messagesToRemove,
                options: $this->buildSummarizationOptions($context),
            );

            // Mark original messages as summarized
            $this->summarizationService->markAsSummarized(
                messageIds: $tokenBudgetResult->removedMessageIds,
                summary: $summary,
            );

            // Create a synthetic summary message
            $syntheticMessage = ChatMessage::system("[Conversation Summary]\n\n".$summary->content)
                ->withTokenCount($summary->token_count);

            // Build the final message list: system prompt + synthetic summary + kept messages
            $finalMessages = $this->buildFinalMessages(
                originalMessages: $messages,
                keptMessages: $tokenBudgetResult->messages,
                syntheticMessage: $syntheticMessage,
            );

            // Enforce tool call chain integrity
            $finalMessages = $this->enforceToolCallIntegrity($finalMessages, $messages);

            $durationMs = (hrtime(true) - $startTime) / 1e6;

            // Dispatch event
            ConversationSummarized::dispatch(
                conversationId: $context->conversation->id,
                summaryId: $summary->id,
                summarizedMessageCount: count($messagesToRemove),
                originalTokenCount: $summary->original_token_count,
                summaryTokenCount: $summary->token_count,
                compressionRatio: $summary->getCompressionRatio(),
                backend: $summary->backend_used,
                durationMs: $durationMs,
            );

            Log::info('[SummarizationStrategy] Successfully summarized messages', [
                'conversation_id' => $context->conversation->id,
                'summarized_count' => count($messagesToRemove),
                'original_tokens' => $summary->original_token_count,
                'summary_tokens' => $summary->token_count,
                'compression_ratio' => $summary->getCompressionRatio(),
                'duration_ms' => $durationMs,
            ]);

            return new FilterResult(
                messages: $finalMessages,
                originalCount: count($messages),
                filteredCount: count($finalMessages),
                removedMessageIds: $tokenBudgetResult->removedMessageIds,
                strategyUsed: $this->name(),
                durationMs: $durationMs,
                metadata: [
                    'summary_id' => $summary->id,
                    'summarized_count' => count($messagesToRemove),
                    'compression_ratio' => $summary->getCompressionRatio(),
                ],
            );
        } catch (SummarizationException $e) {
            Log::warning('[SummarizationStrategy] Summarization failed, falling back to token_budget', [
                'conversation_id' => $context->conversation->id,
                'error' => $e->getMessage(),
            ]);

            return $this->wrapResult($tokenBudgetResult, $startTime);
        } catch (Throwable $e) {
            Log::error('[SummarizationStrategy] Unexpected error during summarization', [
                'conversation_id' => $context->conversation?->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->wrapResult($tokenBudgetResult, $startTime);
        }
    }

    /**
     * Get messages that would be removed based on IDs.
     *
     * @param  array<ChatMessage>  $messages
     * @param  array<string>  $removedIds
     * @return array<ChatMessage>
     */
    private function getMessagesToRemove(array $messages, array $removedIds): array
    {
        $toRemove = [];

        foreach ($messages as $message) {
            $id = $this->getMessageId($message);
            if (in_array($id, $removedIds, true)) {
                $toRemove[] = $message;
            }
        }

        return $toRemove;
    }

    /**
     * Build summarization options from context.
     *
     * @return array<string, mixed>
     */
    private function buildSummarizationOptions(FilterContext $context): array
    {
        return [
            'target_tokens' => $context->options['target_tokens'] ?? config('ai.summarization.target_tokens', self::DEFAULT_TARGET_TOKENS),
            'min_messages' => $context->options['min_messages'] ?? config('ai.summarization.min_messages_for_summary', self::DEFAULT_MIN_MESSAGES),
            'backend' => $context->options['summarization_backend'] ?? config('ai.summarization.backend'),
            'model' => $context->options['summarization_model'] ?? config('ai.summarization.model'),
        ];
    }

    /**
     * Build the final message list with synthetic summary.
     *
     * @param  array<ChatMessage>  $originalMessages
     * @param  array<ChatMessage>  $keptMessages
     * @return array<ChatMessage>
     */
    private function buildFinalMessages(
        array $originalMessages,
        array $keptMessages,
        ChatMessage $syntheticMessage,
    ): array {
        $result = [];

        // Keep system prompt if present
        if (! empty($originalMessages) && $originalMessages[0]->role === 'system') {
            $result[] = $originalMessages[0];
        }

        // Add synthetic summary after system prompt
        $result[] = $syntheticMessage;

        // Add kept messages (excluding system prompt since we already added it)
        foreach ($keptMessages as $message) {
            if ($message->role !== 'system') {
                $result[] = $message;
            }
        }

        return $result;
    }

    /**
     * Wrap a token budget result with this strategy's name.
     */
    private function wrapResult(FilterResult $result, int $startTime): FilterResult
    {
        $durationMs = (hrtime(true) - $startTime) / 1e6;

        return new FilterResult(
            messages: $result->messages,
            originalCount: $result->originalCount,
            filteredCount: $result->filteredCount,
            removedMessageIds: $result->removedMessageIds,
            strategyUsed: $this->name().' (fallback: token_budget)',
            durationMs: $durationMs,
        );
    }
}
