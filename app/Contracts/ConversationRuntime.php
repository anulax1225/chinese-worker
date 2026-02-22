<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ChatMessage;
use App\Models\Agent;
use App\Models\User;

interface ConversationRuntime
{
    /** Unique identifier (int for DB, string UUID for in-memory). */
    public function getId(): int|string;

    public function getAgent(): Agent;

    public function getUser(): ?User;

    /** Client tool schemas array as stored on conversation. */
    public function getClientToolSchemas(): array;

    /** Whether this runtime has attached documents. */
    public function hasDocuments(): bool;

    /** Whether this runtime persists to database. */
    public function isPersistent(): bool;

    // --- Context limit ---

    public function getContextLimit(): ?int;

    public function setContextLimit(int $limit): void;

    // --- Turn management ---

    public function getTurnCount(): int;

    public function getRequestTurnCount(): int;

    public function getMaxTurns(): int;

    public function incrementTurn(): void;

    public function incrementRequestTurn(): void;

    public function resetRequestTurnCount(): void;

    // --- Messages ---

    public function addMessage(ChatMessage $message): void;

    /** @return array<ChatMessage> */
    public function getMessages(): array;

    public function getMessageCount(): int;

    // --- Token tracking ---

    public function addTokenUsage(int $promptTokens, int $completionTokens): void;

    public function getTotalTokens(): int;

    public function getPromptTokens(): int;

    public function getCompletionTokens(): int;

    public function isApproachingContextLimit(float $threshold = 0.8): bool;

    // --- Status transitions ---

    public function markAsCompleted(): void;

    public function markAsCancelled(): void;

    public function markAsFailed(): void;

    public function isCancelled(): bool;

    // --- Snapshot (first turn debug data) ---

    public function storeSnapshot(string $systemPrompt, ?array $context, ?array $modelConfig): void;

    // --- Tool request state ---

    public function setPendingToolRequest(array $toolRequest): void;

    // --- Refresh from storage (cancellation check for DB runtime) ---

    public function refresh(): void;
}
