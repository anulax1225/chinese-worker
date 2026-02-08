<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

readonly class ContextFilterResolutionFailed
{
    use Dispatchable;

    public function __construct(
        public string $strategyName,
        public ?int $agentId = null,
        public ?int $conversationId = null,
    ) {}
}
