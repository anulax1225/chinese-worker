<?php

namespace App\Jobs;

use App\DTOs\ModelPullProgress;
use App\Services\AIBackendManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PullModelJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 7200; // 2 hours for large models

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(
        public string $backend,
        public string $modelName,
        public string $pullId,
        public int $userId,
    ) {}

    /**
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'model-pull',
            "backend:{$this->backend}",
            "user:{$this->userId}",
            "pull:{$this->pullId}",
        ];
    }

    public function handle(AIBackendManager $manager): void
    {
        $channel = "model-pull:{$this->pullId}:events";

        try {
            $driver = $manager->driver($this->backend);

            if (! $driver->supportsModelManagement()) {
                $this->broadcastError($channel, 'This backend does not support model management');

                return;
            }

            $this->broadcast($channel, 'started', [
                'model' => $this->modelName,
                'backend' => $this->backend,
            ]);

            $driver->pullModel($this->modelName, function (ModelPullProgress $progress) use ($channel) {
                $this->broadcast($channel, 'progress', $progress->toArray());
            });

            $this->broadcast($channel, 'completed', [
                'model' => $this->modelName,
                'backend' => $this->backend,
            ]);

        } catch (\Exception $e) {
            Log::error('Model pull failed', [
                'backend' => $this->backend,
                'model' => $this->modelName,
                'error' => $e->getMessage(),
            ]);

            $this->broadcastError($channel, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function broadcast(string $channel, string $event, array $data): void
    {
        $payload = [
            'event' => $event,
            'pull_id' => $this->pullId,
            'timestamp' => now()->toISOString(),
            'data' => $data,
        ];

        Redis::rpush($channel, json_encode($payload));
        Redis::expire($channel, 3600);
    }

    protected function broadcastError(string $channel, string $error): void
    {
        $this->broadcast($channel, 'failed', ['error' => $error]);
    }
}
