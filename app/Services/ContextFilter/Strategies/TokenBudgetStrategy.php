<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\Strategies;

use App\Contracts\ContextFilterStrategy;
use App\Contracts\TokenEstimator;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\DTOs\FilterResult;
use App\Exceptions\InvalidOptionsException;
use App\Services\ContextFilter\Strategies\Concerns\PreservesToolCallChains;

class TokenBudgetStrategy implements ContextFilterStrategy
{
    use PreservesToolCallChains;

    private const DEFAULT_BUDGET_PERCENTAGE = 0.8;

    private const DEFAULT_RESERVE_TOKENS = 0;

    public function __construct(
        private readonly TokenEstimator $tokenEstimator,
    ) {}

    /**
     * Strategy identifier.
     */
    public function name(): string
    {
        return 'token_budget';
    }

    /**
     * Validate strategy options.
     */
    public function validateOptions(array $options): void
    {
        if (isset($options['budget_percentage'])) {
            $percentage = $options['budget_percentage'];
            if (! is_numeric($percentage) || $percentage <= 0 || $percentage > 1) {
                throw InvalidOptionsException::invalidValue(
                    option: 'budget_percentage',
                    reason: 'must be a number between 0 (exclusive) and 1 (inclusive)',
                    strategy: $this->name(),
                    options: $options,
                );
            }
        }

        if (isset($options['reserve_tokens'])) {
            if (! is_int($options['reserve_tokens']) || $options['reserve_tokens'] < 0) {
                throw InvalidOptionsException::invalidValue(
                    option: 'reserve_tokens',
                    reason: 'must be a non-negative integer',
                    strategy: $this->name(),
                    options: $options,
                );
            }
        }
    }

    /**
     * Fit messages within token budget, removing oldest messages first.
     */
    public function filter(FilterContext $context): FilterResult
    {
        $startTime = hrtime(true);

        $messages = $context->messages;
        $budgetPercentage = $context->options['budget_percentage'] ?? self::DEFAULT_BUDGET_PERCENTAGE;
        $reserveTokens = $context->options['reserve_tokens'] ?? self::DEFAULT_RESERVE_TOKENS;

        // Calculate available budget
        $availableBudget = $context->getAvailableBudget();
        $targetBudget = (int) ($availableBudget * $budgetPercentage) - $reserveTokens;

        if ($targetBudget <= 0) {
            // Return only preserved messages if no budget
            return $this->filterToPreservedOnly($messages, $startTime);
        }

        // Categorize messages
        $systemPrompt = null;
        $pinnedMessages = [];
        $regularMessages = [];

        foreach ($messages as $index => $message) {
            if ($index === 0 && $message->role === 'system') {
                $systemPrompt = $message;
            } elseif ($this->isPinned($message)) {
                $pinnedMessages[] = $message;
            } else {
                $regularMessages[] = $message;
            }
        }

        // Calculate tokens for preserved messages
        $preservedTokens = 0;
        $preserved = [];

        if ($systemPrompt !== null) {
            $preservedTokens += $this->estimateTokens($systemPrompt);
            $preserved[] = $systemPrompt;
        }

        foreach ($pinnedMessages as $msg) {
            $preservedTokens += $this->estimateTokens($msg);
            $preserved[] = $msg;
        }

        // Calculate remaining budget for regular messages
        $remainingBudget = $targetBudget - $preservedTokens;

        if ($remainingBudget <= 0) {
            // Only preserved messages fit
            $kept = $this->enforceToolCallIntegrity($preserved, $messages);
            $removedIds = $this->getRemovedIds($messages, $kept);
            $durationMs = (hrtime(true) - $startTime) / 1e6;

            return new FilterResult(
                messages: $kept,
                originalCount: count($messages),
                filteredCount: count($kept),
                removedMessageIds: $removedIds,
                strategyUsed: $this->name(),
                durationMs: $durationMs,
            );
        }

        // Build from the end (most recent) until budget exhausted
        $keptRegular = [];
        $usedTokens = 0;

        for ($i = count($regularMessages) - 1; $i >= 0; $i--) {
            $msg = $regularMessages[$i];
            $tokens = $this->estimateTokens($msg);

            if ($usedTokens + $tokens <= $remainingBudget) {
                array_unshift($keptRegular, $msg);
                $usedTokens += $tokens;
            }
        }

        // Combine preserved and kept regular messages
        $kept = array_merge($preserved, $keptRegular);

        // Enforce tool call chain integrity
        $kept = $this->enforceToolCallIntegrity($kept, $messages);

        $removedIds = $this->getRemovedIds($messages, $kept);
        $durationMs = (hrtime(true) - $startTime) / 1e6;

        return new FilterResult(
            messages: $kept,
            originalCount: count($messages),
            filteredCount: count($kept),
            removedMessageIds: $removedIds,
            strategyUsed: $this->name(),
            durationMs: $durationMs,
        );
    }

    /**
     * Estimate tokens for a message.
     */
    private function estimateTokens(ChatMessage $message): int
    {
        // Use cached token count if available
        if ($message->tokenCount !== null) {
            return $message->tokenCount;
        }

        return $this->tokenEstimator->estimate($message);
    }

    /**
     * Check if a message is pinned.
     */
    private function isPinned(ChatMessage $message): bool
    {
        // ChatMessage doesn't have a pinned property
        // Pinned status is checked at the service level
        return false;
    }

    /**
     * Filter to preserved messages only.
     *
     * @param  array<int, ChatMessage>  $messages
     */
    private function filterToPreservedOnly(array $messages, int $startTime): FilterResult
    {
        $preserved = [];

        foreach ($messages as $index => $message) {
            if ($index === 0 && $message->role === 'system') {
                $preserved[] = $message;
            } elseif ($this->isPinned($message)) {
                $preserved[] = $message;
            }
        }

        $preserved = $this->enforceToolCallIntegrity($preserved, $messages);
        $removedIds = $this->getRemovedIds($messages, $preserved);
        $durationMs = (hrtime(true) - $startTime) / 1e6;

        return new FilterResult(
            messages: $preserved,
            originalCount: count($messages),
            filteredCount: count($preserved),
            removedMessageIds: $removedIds,
            strategyUsed: $this->name(),
            durationMs: $durationMs,
        );
    }

    /**
     * Get IDs of removed messages.
     *
     * @param  array<int, ChatMessage>  $all
     * @param  array<int, ChatMessage>  $kept
     * @return array<int, string>
     */
    private function getRemovedIds(array $all, array $kept): array
    {
        $keptIds = $this->getMessageIds($kept);
        $removed = [];

        foreach ($all as $msg) {
            $id = $this->getMessageId($msg);
            if (! in_array($id, $keptIds, true)) {
                $removed[] = $id;
            }
        }

        return $removed;
    }
}
