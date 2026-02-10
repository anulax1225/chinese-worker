<?php

namespace App\Services\AI;

use App\DTOs\ModelConfig;
use App\DTOs\NormalizedModelConfig;
use App\Models\Agent;

class ModelConfigNormalizer
{
    /**
     * Default configuration values by backend driver.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $driverDefaults = [
        'ollama' => [
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'context_length' => 4096,
            'timeout' => 120,
        ],
        'anthropic' => [
            'temperature' => 1.0,
            'max_tokens' => 4096,
            'context_length' => 200000,
            'timeout' => 120,
        ],
        'openai' => [
            'temperature' => 1.0,
            'max_tokens' => 4096,
            'context_length' => 128000,
            'timeout' => 120,
        ],
        'huggingface' => [
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'context_length' => 131072,
            'timeout' => 120,
        ],
        'vllm' => [
            'temperature' => 0.7,
            'max_tokens' => 4096,
            'context_length' => 131072,
            'timeout' => 120,
        ],
    ];

    /**
     * Known model limits for capability checking.
     *
     * @var array<string, array<string, array<string, int>>>
     */
    protected array $modelLimits = [
        'ollama' => [
            'llama3.1' => ['max_tokens' => 8192, 'context_length' => 131072],
            'llama3.2' => ['max_tokens' => 8192, 'context_length' => 131072],
            'qwen2.5' => ['max_tokens' => 8192, 'context_length' => 32768],
            'mistral' => ['max_tokens' => 8192, 'context_length' => 32768],
            'deepseek-r1' => ['max_tokens' => 8192, 'context_length' => 65536],
        ],
        'anthropic' => [
            'claude-sonnet-4-5-20250929' => ['max_tokens' => 8192, 'context_length' => 200000],
            'claude-3-opus' => ['max_tokens' => 4096, 'context_length' => 200000],
            'claude-3-sonnet' => ['max_tokens' => 4096, 'context_length' => 200000],
            'claude-3-haiku' => ['max_tokens' => 4096, 'context_length' => 200000],
        ],
        'openai' => [
            'gpt-4' => ['max_tokens' => 8192, 'context_length' => 128000],
            'gpt-4-turbo' => ['max_tokens' => 4096, 'context_length' => 128000],
            'gpt-4o' => ['max_tokens' => 16384, 'context_length' => 128000],
            'gpt-3.5-turbo' => ['max_tokens' => 4096, 'context_length' => 16385],
        ],
        'huggingface' => [
            'meta-llama/Llama-3.1-8B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'meta-llama/Llama-3.1-70B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'Qwen/Qwen2.5-72B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'mistralai/Mistral-7B-Instruct-v0.3' => ['max_tokens' => 8192, 'context_length' => 32768],
            'deepseek-ai/DeepSeek-R1' => ['max_tokens' => 8192, 'context_length' => 65536],
        ],
        'vllm' => [
            'meta-llama/Llama-3.1-8B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'meta-llama/Llama-3.1-70B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'Qwen/Qwen2.5-72B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'Qwen/Qwen2.5-7B-Instruct' => ['max_tokens' => 8192, 'context_length' => 131072],
            'microsoft/phi-4' => ['max_tokens' => 4096, 'context_length' => 16384],
        ],
    ];

    /**
     * Normalize configuration for an agent.
     * Merge priority: Global config → Backend defaults → Agent overrides
     */
    public function normalize(Agent $agent): NormalizedModelConfig
    {
        $warnings = [];
        $backendName = $agent->ai_backend ?? config('ai.default', 'ollama');
        $backendConfig = config("ai.backends.{$backendName}", []);
        $driver = $backendConfig['driver'] ?? 'ollama';

        // Build base config from global config/ai.php
        $globalConfig = $this->extractGlobalConfig($backendConfig, $driver);

        // Get driver defaults for any missing values
        $driverDefaults = ModelConfig::fromArray($this->driverDefaults[$driver] ?? $this->driverDefaults['ollama']);

        // Get agent's custom config
        $agentConfig = $agent->getModelConfigDto();

        // Merge: driver defaults <- global config <- agent overrides
        $merged = $driverDefaults->merge($globalConfig)->merge($agentConfig);

        // Determine final model
        $model = $merged->model ?? $backendConfig['model'] ?? $this->getDefaultModel($driver);

        // Apply capability checks and clamp values
        $limits = $this->getModelLimits($driver, $model);

        // Clamp max_tokens
        $maxTokens = $merged->maxTokens ?? $this->driverDefaults[$driver]['max_tokens'] ?? 4096;
        if ($limits && $maxTokens > $limits['max_tokens']) {
            $warnings[] = "max_tokens clamped from {$maxTokens} to {$limits['max_tokens']} for model {$model}";
            $maxTokens = $limits['max_tokens'];
        }

        // Clamp context_length
        $contextLength = $merged->contextLength ?? $this->driverDefaults[$driver]['context_length'] ?? 4096;
        if ($limits && $contextLength > $limits['context_length']) {
            $warnings[] = "context_length clamped from {$contextLength} to {$limits['context_length']} for model {$model}";
            $contextLength = $limits['context_length'];
        }

        // Clamp temperature to valid range
        $temperature = $merged->temperature ?? 0.7;
        if ($temperature < 0) {
            $warnings[] = "temperature clamped from {$temperature} to 0";
            $temperature = 0.0;
        } elseif ($temperature > 2) {
            $warnings[] = "temperature clamped from {$temperature} to 2";
            $temperature = 2.0;
        }

        // Check for unsupported parameters
        if ($driver === 'openai' && $merged->topK !== null) {
            $warnings[] = 'top_k is not supported by OpenAI and will be ignored';
        }

        if ($driver === 'ollama' && ($merged->frequencyPenalty !== null || $merged->presencePenalty !== null)) {
            $warnings[] = 'frequency_penalty and presence_penalty are not supported by Ollama and will be ignored';
        }

        return new NormalizedModelConfig(
            model: $model,
            temperature: $temperature,
            maxTokens: $maxTokens,
            contextLength: $contextLength,
            timeout: $merged->timeout ?? $this->driverDefaults[$driver]['timeout'] ?? 120,
            topP: $merged->topP,
            topK: $driver !== 'openai' ? $merged->topK : null,
            frequencyPenalty: $driver !== 'ollama' ? $merged->frequencyPenalty : null,
            presencePenalty: $driver !== 'ollama' ? $merged->presencePenalty : null,
            stopSequences: $merged->stopSequences,
            validationWarnings: $warnings,
        );
    }

    /**
     * Extract model config from global backend configuration.
     */
    protected function extractGlobalConfig(array $backendConfig, string $driver): ModelConfig
    {
        $data = [
            'model' => $backendConfig['model'] ?? null,
            'timeout' => $backendConfig['timeout'] ?? null,
        ];

        // Ollama stores options in nested 'options' key
        if ($driver === 'ollama') {
            $options = $backendConfig['options'] ?? [];
            $data['temperature'] = $options['temperature'] ?? null;
            $data['context_length'] = $options['num_ctx'] ?? null;
            $data['top_p'] = $options['top_p'] ?? null;
            $data['top_k'] = $options['top_k'] ?? null;
        } else {
            // Anthropic and OpenAI have flat config
            $data['temperature'] = $backendConfig['temperature'] ?? null;
            $data['max_tokens'] = $backendConfig['max_tokens'] ?? null;
            $data['top_p'] = $backendConfig['top_p'] ?? null;
            $data['top_k'] = $backendConfig['top_k'] ?? null;
        }

        return ModelConfig::fromArray($data);
    }

    /**
     * Get default model for a driver.
     */
    protected function getDefaultModel(string $driver): string
    {
        return match ($driver) {
            'ollama' => 'llama3.1',
            'anthropic' => 'claude-sonnet-4-5-20250929',
            'openai' => 'gpt-4',
            'huggingface' => 'meta-llama/Llama-3.1-8B-Instruct',
            'vllm' => 'meta-llama/Llama-3.1-8B-Instruct',
            default => 'llama3.1',
        };
    }

    /**
     * Get model limits if known.
     *
     * @return array{max_tokens: int, context_length: int}|null
     */
    protected function getModelLimits(string $driver, string $model): ?array
    {
        // Try exact match first
        if (isset($this->modelLimits[$driver][$model])) {
            return $this->modelLimits[$driver][$model];
        }

        // Try prefix match for versioned models (e.g., llama3.1:8b matches llama3.1)
        $baseModel = explode(':', $model)[0];
        if (isset($this->modelLimits[$driver][$baseModel])) {
            return $this->modelLimits[$driver][$baseModel];
        }

        return null;
    }

    /**
     * Get available driver names.
     *
     * @return array<string>
     */
    public function getAvailableDrivers(): array
    {
        return array_keys($this->driverDefaults);
    }

    /**
     * Get default configuration for a driver.
     *
     * @return array<string, mixed>
     */
    public function getDriverDefaults(string $driver): array
    {
        return $this->driverDefaults[$driver] ?? $this->driverDefaults['ollama'];
    }
}
