<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class AgenticLoopResult
{
    /**
     * @param  'completed'|'waiting_for_tool'|'failed'|'max_turns'|'continue'|'cancelled'  $status
     * @param  array<string, mixed>|null  $toolRequest
     */
    public function __construct(
        public string $status,
        public ?array $toolRequest = null,
        public ?string $error = null,
        public int $turnsExecuted = 0,
    ) {}

    public static function completed(int $turnsExecuted): self
    {
        return new self('completed', turnsExecuted: $turnsExecuted);
    }

    public static function waitingForTool(array $toolRequest, int $turnsExecuted): self
    {
        return new self('waiting_for_tool', toolRequest: $toolRequest, turnsExecuted: $turnsExecuted);
    }

    public static function failed(string $error, int $turnsExecuted): self
    {
        return new self('failed', error: $error, turnsExecuted: $turnsExecuted);
    }

    public static function maxTurns(int $turnsExecuted): self
    {
        return new self('max_turns', turnsExecuted: $turnsExecuted);
    }

    public static function cancelled(int $turnsExecuted): self
    {
        return new self('cancelled', turnsExecuted: $turnsExecuted);
    }

    public static function continue(int $turnsExecuted): self
    {
        return new self('continue', turnsExecuted: $turnsExecuted);
    }
}
