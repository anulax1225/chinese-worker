<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class SummarizationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $conversationId = null,
        public readonly ?string $backend = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * Create an exception for AI API failure.
     */
    public static function apiFailure(
        string $message,
        ?int $conversationId = null,
        ?string $backend = null,
        ?Throwable $previous = null,
    ): self {
        return new self(
            message: "Summarization API failure: {$message}",
            conversationId: $conversationId,
            backend: $backend,
            previous: $previous,
        );
    }

    /**
     * Create an exception for empty response from AI.
     */
    public static function emptyResponse(?int $conversationId = null, ?string $backend = null): self
    {
        return new self(
            message: 'Summarization returned empty response',
            conversationId: $conversationId,
            backend: $backend,
        );
    }

    /**
     * Create an exception when summary exceeds token limit.
     */
    public static function summaryTooLarge(
        int $tokens,
        int $limit,
        ?int $conversationId = null,
    ): self {
        return new self(
            message: "Summary too large: {$tokens} tokens exceeds limit of {$limit}",
            conversationId: $conversationId,
        );
    }

    /**
     * Create an exception for insufficient messages to summarize.
     */
    public static function insufficientMessages(
        int $count,
        int $minimum,
        ?int $conversationId = null,
    ): self {
        return new self(
            message: "Insufficient messages to summarize: {$count} messages, minimum required is {$minimum}",
            conversationId: $conversationId,
        );
    }
}
