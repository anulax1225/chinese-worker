<?php

use App\Contracts\AIBackendInterface;
use App\DTOs\AIModel;
use App\DTOs\AIResponse;
use App\Models\Agent;
use App\Services\AIBackendManager;
use Illuminate\Support\Facades\Config;

describe('AIBackendManager', function () {
    test('can get default driver', function () {
        Config::set('ai.default', 'ollama');

        $manager = app(AIBackendManager::class);
        $defaultDriver = $manager->getDefaultDriver();

        expect($defaultDriver)->toBe('ollama');
    });

    test('can get all registered drivers', function () {
        Config::set('ai.backends', [
            'ollama' => ['driver' => 'ollama'],
            'claude' => ['driver' => 'anthropic'],
            'openai' => ['driver' => 'openai'],
        ]);

        $manager = app(AIBackendManager::class);
        $drivers = $manager->getDrivers();

        expect($drivers)->toHaveCount(3);
        expect($drivers)->toContain('ollama');
        expect($drivers)->toContain('claude');
        expect($drivers)->toContain('openai');
    });

    test('throws exception for undefined backend', function () {
        $manager = app(AIBackendManager::class);

        expect(fn () => $manager->driver('nonexistent'))
            ->toThrow(InvalidArgumentException::class, 'Backend [nonexistent] is not defined.');
    });

    test('can extend manager with custom driver', function () {
        Config::set('ai.default', 'custom');
        Config::set('ai.backends.custom', [
            'driver' => 'custom',
        ]);

        $mockBackend = new class implements AIBackendInterface
        {
            public function execute(Agent $agent, array $context): AIResponse
            {
                return new AIResponse('test', 'custom-model', 0, 'stop');
            }

            public function streamExecute(Agent $agent, array $context, callable $callback): AIResponse
            {
                return new AIResponse('test', 'custom-model', 0, 'stop');
            }

            public function validateConfig(array $config): bool
            {
                return true;
            }

            public function getCapabilities(): array
            {
                return [];
            }

            public function listModels(): array
            {
                return [];
            }

            public function disconnect(): void
            {
                // No-op for test
            }

            public function formatMessage(\App\DTOs\ChatMessage $message): array
            {
                return ['role' => $message->role, 'content' => $message->content];
            }

            public function parseToolCall(array $data): \App\DTOs\ToolCall
            {
                return new \App\DTOs\ToolCall(
                    id: $data['id'] ?? uniqid('call_'),
                    name: $data['name'] ?? '',
                    arguments: $data['arguments'] ?? []
                );
            }

            public function supportsModelManagement(): bool
            {
                return false;
            }

            public function pullModel(string $modelName, callable $onProgress): void
            {
                // No-op for test
            }

            public function deleteModel(string $modelName): void
            {
                // No-op for test
            }

            public function showModel(string $modelName): AIModel
            {
                return new AIModel(name: $modelName);
            }
        };

        $manager = app(AIBackendManager::class);
        $manager->extend('custom', fn ($config) => $mockBackend);

        $driver = $manager->driver('custom');

        expect($driver)->toBeInstanceOf(AIBackendInterface::class);
    });

    test('manager is registered as singleton', function () {
        $manager1 = app(AIBackendManager::class);
        $manager2 = app(AIBackendManager::class);

        expect($manager1)->toBe($manager2);
    });
});
