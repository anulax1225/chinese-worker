<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class ContextFilterOptionsInvalid
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public int $agentId,
        public string $strategyName,
        public array $options,
        public string $errorMessage,
    ) {}
}
