<?php

namespace App\Http\Controllers\Concerns;

trait StreamsServerSentEvents
{
    /**
     * Send an SSE event to the stream.
     *
     * @param  array<string, mixed>  $data
     */
    protected function sendSSEEvent(string $event, array $data): void
    {
        echo "event: {$event}\n";
        echo 'data: '.json_encode($data)."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Prepare the output buffer for SSE streaming.
     */
    protected function prepareSSEStream(): void
    {
        if (ob_get_level()) {
            ob_end_clean();
        }

        // 2KB padding for nginx buffering
        echo ':'.str_repeat(' ', 2048)."\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }

    /**
     * Get standard SSE response headers.
     *
     * @return array<string, string>
     */
    protected function getSSEHeaders(): array
    {
        return [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ];
    }

    /**
     * Send an SSE heartbeat comment.
     */
    protected function sendSSEHeartbeat(): void
    {
        echo ": heartbeat\n\n";
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
}
