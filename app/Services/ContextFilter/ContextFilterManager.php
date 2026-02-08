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
     * Filter messages for a conversation using its configured strategy.
     */
    public function filterForConversation(
        Conversation $conversation,
        int $maxOutputTokens = 4096,
        int $toolDefinitionTokens = 0,
    ): FilterResult {
        $agent = $conversation->agent;
        $strategyName = $agent?->context_strategy
            ?? config('ai.context_filter.default_strategy', 'token_budget');
        $options = $agent?->context_options
            ?? config('ai.context_filter.default_options', []);

        $strategy = $this->resolve($strategyName);

        $context = FilterContext::fromConversation(
            conversation: $conversation,
            options: $options,
            maxOutputTokens: $maxOutputTokens,
            toolDefinitionTokens: $toolDefinitionTokens,
        );

        return $strategy->filter($context);
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
