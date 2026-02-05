<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class SearchException extends RuntimeException
{
    public function __construct(
        string $message = 'Search operation failed',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for timeout.
     */
    public static function timeout(int $seconds = 0): self
    {
        $message = $seconds > 0
            ? "Search request timed out after {$seconds} seconds"
            : 'Search request timed out';

        return new self($message);
    }

    /**
     * Create exception for service unavailable.
     */
    public static function unavailable(string $service = 'Search'): self
    {
        return new self("{$service} service is unavailable");
    }

    /**
     * Create exception for invalid query.
     */
    public static function invalidQuery(string $reason = 'Query cannot be empty'): self
    {
        return new self("Invalid search query: {$reason}");
    }

    /**
     * Create exception for connection failure.
     */
    public static function connectionFailed(string $url, ?Throwable $previous = null): self
    {
        return new self("Failed to connect to search service at {$url}", 0, $previous);
    }

    /**
     * Create exception for invalid response.
     */
    public static function invalidResponse(string $reason = 'Unexpected response format'): self
    {
        return new self("Invalid search response: {$reason}");
    }
}
