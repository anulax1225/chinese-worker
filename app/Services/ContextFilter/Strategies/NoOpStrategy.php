<?php

declare(strict_types=1);

namespace App\Services\ContextFilter\Strategies;

use App\Contracts\ContextFilterStrategy;
use App\DTOs\FilterContext;
use App\DTOs\FilterResult;

class NoOpStrategy implements ContextFilterStrategy
{
    /**
     * Strategy identifier.
     */
    public function name(): string
    {
        return 'noop';
    }

    /**
     * No options required for pass-through strategy.
     */
    public function validateOptions(array $options): void
    {
        // No validation needed - accepts any options
    }

    /**
     * Pass through all messages without filtering.
     */
    public function filter(FilterContext $context): FilterResult
    {
        return FilterResult::noOp($context->messages, $this->name());
    }
}
