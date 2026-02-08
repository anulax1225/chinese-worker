<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\FilterContext;
use App\DTOs\FilterResult;
use App\Exceptions\InvalidOptionsException;

interface ContextFilterStrategy
{
    /**
     * Strategy identifier for configuration.
     */
    public function name(): string;

    /**
     * Validate that options are correct for this strategy.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws InvalidOptionsException
     */
    public function validateOptions(array $options): void;

    /**
     * Filter messages for context optimization.
     *
     * Postconditions:
     * - Returned messages MUST maintain original relative order
     * - System prompt (position 0) is never removed
     * - Last user message is never removed
     * - Pinned messages are never removed
     * - Tool call chains are never broken (both call and result kept or both removed)
     */
    public function filter(FilterContext $context): FilterResult;
}
