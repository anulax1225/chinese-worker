<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\Strategies;

use App\Contracts\ContextFilterStrategy;
use App\DTOs\ChatMessage;
use App\DTOs\FilterContext;
use App\DTOs\FilterResult;
use App\Exceptions\InvalidOptionsException;
use App\Services\ContextFilter\Strategies\Concerns\PreservesToolCallChains;

class SlidingWindowStrategy implements ContextFilterStrategy
{
    use PreservesToolCallChains;

    private const DEFAULT_WINDOW_SIZE = 50;

    /**
     * Strategy identifier.
     */
    public function name(): string
    {
        return 'sliding_window';
    }

    /**
     * Validate strategy options.
     */
    public function validateOptions(array $options): void
    {
        if (isset($options['window_size'])) {
            if (! is_int($options['window_size']) || $options['window_size'] < 1) {
                throw InvalidOptionsException::invalidValue(
                    option: 'window_size',
                    reason: 'must be a positive integer',
                    strategy: $this->name(),
                    options: $options,
                );
            }
        }
    }

    /**
     * Keep the most recent N messages, preserving system prompt and pinned messages.
     */
    public function filter(FilterContext $context): FilterResult
    {
        $startTime = hrtime(true);

        $messages = $context->messages;
        $windowSize = $context->options['window_size'] ?? self::DEFAULT_WINDOW_SIZE;

        if (count($messages) <= $windowSize) {
            return FilterResult::noOp($messages, $this->name());
        }

        $kept = [];
        $removedIds = [];

        // Separate preserved and non-preserved messages
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

        // Always keep system prompt
        if ($systemPrompt !== null) {
            $kept[] = $systemPrompt;
        }

        // Always keep pinned messages
        foreach ($pinnedMessages as $msg) {
            $kept[] = $msg;
        }

        // Calculate remaining window size
        $remainingWindow = $windowSize - count($kept);

        if ($remainingWindow > 0 && count($regularMessages) > 0) {
            // Keep the most recent messages
            $startIndex = max(0, count($regularMessages) - $remainingWindow);
            $keptRegular = array_slice($regularMessages, $startIndex);
            $removed = array_slice($regularMessages, 0, $startIndex);

            foreach ($keptRegular as $msg) {
                $kept[] = $msg;
            }

            foreach ($removed as $msg) {
                $removedIds[] = $this->getMessageId($msg);
            }
        } else {
            // All regular messages are removed
            foreach ($regularMessages as $msg) {
                $removedIds[] = $this->getMessageId($msg);
            }
        }

        // Enforce tool call chain integrity
        $kept = $this->enforceToolCallIntegrity($kept, $messages);

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
     * Check if a message is pinned.
     */
    private function isPinned(ChatMessage $message): bool
    {
        // ChatMessage doesn't have a pinned property yet
        // This will be checked against the Message model when needed
        return false;
    }
}
