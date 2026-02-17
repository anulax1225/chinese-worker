<?php

declare(strict_types=1);

namespace App\Services\ContextFilter;

use App\Contracts\ContextFilterStrategy;
use App\DTOs\FilterContext;
use App\DTOs\FilterResult;
use App\Events\ContextFilterResolutionFailed;
use App\Models\Conversation;
use App\Services\ContextFilter\Strategies\NoOpStrategy;
use Illuminate\Support\Facades\Log;

class ContextFilterManager
{
    /**
     * @param  iterable<ContextFilterStrategy>  $strategies
     */
    public function __construct(
        private readonly iterable $strategies,
        private readonly NoOpStrategy $fallbackStrategy,
    ) {}

    /**
     * Resolve a strategy by name.
     */
    public function resolve(string $name): ContextFilterStrategy
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->name() === $name) {
                return $strategy;
            }
        }

        Log::error("Unknown context filter strategy: {$name}. Falling back to NoOp.");
        event(new ContextFilterResolutionFailed($name));

        return $this->fallbackStrategy;
    }

    /**
     * Check if a strategy exists by name.
     */
    public function hasStrategy(string $name): bool
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->name() === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter messages for a conversation using its configured strategy pipeline.
     *
     * Strategies are run sequentially, with each strategy receiving the output
     * of the previous one. Removed message IDs are accumulated across all strategies.
     */
    public function filterForConversation(
        Conversation $conversation,
        int $maxOutputTokens = 4096,
        int $toolDefinitionTokens = 0,
    ): FilterResult {
        $startTime = hrtime(true);

        $agent = $conversation->agent;
        $options = $agent?->context_options
            ?? config('ai.context_filter.default_options', []);

        // Get the strategy pipeline (array of strategy names)
        $strategyNames = $agent?->getEffectiveContextStrategies()
            ?? [config('ai.context_filter.default_strategy', 'token_budget')];

        // Build initial context
        $context = FilterContext::fromConversation(
            conversation: $conversation,
            options: $options,
            maxOutputTokens: $maxOutputTokens,
            toolDefinitionTokens: $toolDefinitionTokens,
        );

        $originalCount = \count($context->messages);

        // Run strategies in sequence (pipeline pattern)
        $allRemovedIds = [];
        $strategiesApplied = [];
        $result = null;

        foreach ($strategyNames as $strategyName) {
            $strategy = $this->resolve($strategyName);
            $result = $strategy->filter($context);

            // Track which strategies were applied
            $strategiesApplied[] = $strategyName;

            // Accumulate removed message IDs
            $allRemovedIds = [...$allRemovedIds, ...$result->removedMessageIds];

            // Update context for next strategy with filtered messages
            $context = $context->withMessages($result->messages);
        }

        $durationMs = (hrtime(true) - $startTime) / 1e6;

        // If no strategies ran, return a no-op result
        if ($result === null) {
            return FilterResult::noOp($context->messages);
        }

        $filteredCount = \count($result->messages);

        // Return final result with accumulated metadata
        return new FilterResult(
            messages: $result->messages,
            originalCount: $originalCount,
            filteredCount: $filteredCount,
            removedMessageIds: array_unique($allRemovedIds),
            strategyUsed: implode('+', $strategiesApplied),
            durationMs: $durationMs,
            metadata: [
                ...$result->metadata ?? [],
                'strategies_applied' => $strategiesApplied,
                'pipeline_length' => \count($strategiesApplied),
            ],
        );
    }

    /**
     * Filter messages using a specific strategy.
     */
    public function filter(FilterContext $context, string $strategyName): FilterResult
    {
        $strategy = $this->resolve($strategyName);

        return $strategy->filter($context);
    }

    /**
     * Get all available strategy names.
     *
     * @return array<int, string>
     */
    public function availableStrategies(): array
    {
        $names = [];

        foreach ($this->strategies as $strategy) {
            $names[] = $strategy->name();
        }

        return $names;
    }
}
