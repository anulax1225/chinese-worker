<?php

namespace App\Http\Controllers\Concerns;

trait StreamsServerSentEvents
{
    /**
     * Format an SSE event string for use with yield in a Generator.
     *
     * @param  array<string, mixed>  $data
     */
    protected function formatSSEEvent(string $event, array $data): string
    {
        return "event: {$event}\ndata: ".json_encode($data)."\n\n";
    }

    /**
     * Format an SSE heartbeat comment.
     */
    protected function formatSSEHeartbeat(): string
    {
        return ": heartbeat\n\n";
    }

    /**
     * Get standard SSE response headers.
     *
     * @return array<string, string>
     */
    protected function getSSEHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }
}
