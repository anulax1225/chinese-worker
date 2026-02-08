<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class InvalidOptionsException extends Exception
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        string $message,
        public readonly string $strategyName = '',
        public readonly array $options = [],
    ) {
        parent::__construct($message);
    }

    /**
     * Create an exception for a missing required option.
     *
     * @param  array<string, mixed>  $options
     */
    public static function missingOption(string $option, string $strategy, array $options = []): self
    {
        return new self(
            message: "Missing required option '{$option}' for strategy '{$strategy}'",
            strategyName: $strategy,
            options: $options,
        );
    }

    /**
     * Create an exception for an invalid option value.
     *
     * @param  array<string, mixed>  $options
     */
    public static function invalidValue(string $option, string $reason, string $strategy, array $options = []): self
    {
        return new self(
            message: "Invalid value for option '{$option}' in strategy '{$strategy}': {$reason}",
            strategyName: $strategy,
            options: $options,
        );
    }
}
