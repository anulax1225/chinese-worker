<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

class WebFetchException extends RuntimeException
{
    public function __construct(
        string $message = 'Web fetch operation failed',
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Create exception for invalid URL.
     */
    public static function invalidUrl(string $url = ''): self
    {
        $message = $url
            ? "Invalid URL: {$url}"
            : 'Invalid or missing URL';

        return new self($message);
    }

    /**
     * Create exception for timeout.
     */
    public static function timeout(int $seconds = 0): self
    {
        $message = $seconds > 0
            ? "Connection timed out after {$seconds} seconds"
            : 'Connection timed out';

        return new self($message);
    }

    /**
     * Create exception for connection failure.
     */
    public static function connectionFailed(string $url, ?Throwable $previous = null): self
    {
        return new self(
            "Failed to connect to {$url}",
            0,
            $previous
        );
    }

    /**
     * Create exception for HTTP errors.
     */
    public static function httpError(int $statusCode, string $reason = ''): self
    {
        $message = $reason
            ? "HTTP error {$statusCode}: {$reason}"
            : "HTTP error: {$statusCode}";

        return new self($message);
    }

    /**
     * Create exception for unsupported content type.
     */
    public static function unsupportedContentType(string $contentType): self
    {
        return new self("Unsupported content type: {$contentType}");
    }

    /**
     * Create exception for response too large.
     */
    public static function tooLarge(int $size, int $maxSize): self
    {
        $sizeMb = round($size / 1024 / 1024, 2);
        $maxMb = round($maxSize / 1024 / 1024, 2);

        return new self("Response too large ({$sizeMb}MB exceeds {$maxMb}MB limit)");
    }

    /**
     * Create exception for content extraction failure.
     */
    public static function extractionFailed(string $reason = ''): self
    {
        $message = $reason
            ? "Content extraction failed: {$reason}"
            : 'Content extraction failed';

        return new self($message);
    }

    /**
     * Create exception for empty response.
     */
    public static function emptyResponse(): self
    {
        return new self('Server returned an empty response');
    }
}
