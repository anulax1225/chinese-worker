<?php

namespace App\Services\WebFetch;

use App\Contracts\WebFetchClientInterface;
use App\DTOs\WebFetch\FetchRequest;
use App\Exceptions\WebFetchException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class HttpFetchClient implements WebFetchClientInterface
{
    /**
     * @param  array<string>  $allowedContentTypes
     */
    public function __construct(
        protected int $timeout = 15,
        protected int $maxSize = 5242880,
        protected string $userAgent = 'ChineseWorker/1.0',
        protected array $allowedContentTypes = [],
    ) {
        if (empty($this->allowedContentTypes)) {
            $this->allowedContentTypes = [
                'text/html',
                'text/plain',
                'application/json',
                'application/xml',
                'text/xml',
                'application/xhtml+xml',
            ];
        }
    }

    /**
     * {@inheritdoc}
     */
    public function fetch(FetchRequest $request): array
    {
        if (! $request->isValid()) {
            throw WebFetchException::invalidUrl($request->url);
        }

        $timeout = $request->timeout ?? $this->timeout;
        $maxSize = $request->maxSize ?? $this->maxSize;

        try {
            $response = Http::timeout($timeout)
                ->withUserAgent($this->userAgent)
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,text/plain;q=0.8,application/json;q=0.7,*/*;q=0.5',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ])
                ->get($request->url);

            $this->validateResponse($response, $maxSize);

            return [
                'body' => $response->body(),
                'content_type' => $response->header('Content-Type') ?? 'text/html',
                'status_code' => $response->status(),
                'content_length' => strlen($response->body()),
            ];
        } catch (ConnectionException $e) {
            throw WebFetchException::connectionFailed($request->url, $e);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return 'http';
    }

    /**
     * Validate the HTTP response.
     */
    protected function validateResponse(Response $response, int $maxSize): void
    {
        // Check status code
        if (! $response->successful()) {
            throw WebFetchException::httpError(
                $response->status(),
                $response->reason()
            );
        }

        // Check content type
        $contentType = $this->parseContentType($response->header('Content-Type') ?? '');
        if (! $this->isAllowedContentType($contentType)) {
            throw WebFetchException::unsupportedContentType($contentType ?: 'unknown');
        }

        // Check response size
        $size = strlen($response->body());
        if ($size > $maxSize) {
            throw WebFetchException::tooLarge($size, $maxSize);
        }

        // Check for empty response
        if (empty($response->body())) {
            throw WebFetchException::emptyResponse();
        }
    }

    /**
     * Check if content type is allowed.
     */
    protected function isAllowedContentType(string $contentType): bool
    {
        if (empty($contentType)) {
            // Allow empty content type (treat as HTML)
            return true;
        }

        foreach ($this->allowedContentTypes as $allowed) {
            if (str_contains($contentType, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse content type header to get base type.
     */
    protected function parseContentType(string $contentType): string
    {
        $parts = explode(';', $contentType);

        return strtolower(trim($parts[0]));
    }

    /**
     * Create instance from config.
     */
    public static function fromConfig(): self
    {
        return new self(
            timeout: config('webfetch.timeout', 15),
            maxSize: config('webfetch.max_size', 5242880),
            userAgent: config('webfetch.user_agent', 'ChineseWorker/1.0'),
            allowedContentTypes: config('webfetch.allowed_content_types', []),
        );
    }
}
