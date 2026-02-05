<?php

namespace App\Contracts;

use App\DTOs\WebFetch\FetchRequest;

interface WebFetchClientInterface
{
    /**
     * Fetch content from a URL.
     *
     * @return array{body: string, content_type: string, status_code: int, content_length: int}
     */
    public function fetch(FetchRequest $request): array;

    /**
     * Check if the client is available/functional.
     */
    public function isAvailable(): bool;

    /**
     * Get the name of this client implementation.
     */
    public function getName(): string;
}
