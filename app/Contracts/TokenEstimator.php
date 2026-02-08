<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ChatMessage;

interface TokenEstimator
{
    /**
     * Estimate the number of tokens in a message.
     */
    public function estimate(ChatMessage $message): int;

    /**
     * Check if this estimator supports the given model.
     */
    public function supports(string $model): bool;
}
