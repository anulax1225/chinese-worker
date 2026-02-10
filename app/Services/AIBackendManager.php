<?php

namespace App\Services;

use App\Contracts\AIBackendInterface;
use App\DTOs\NormalizedModelConfig;
use App\Models\Agent;
use App\Services\AI\AnthropicBackend;
use App\Services\AI\HuggingFaceBackend;
use App\Services\AI\ModelConfigNormalizer;
use App\Services\AI\OllamaBackend;
use App\Services\AI\OpenAIBackend;
use App\Services\AI\VLLMBackend;
use Closure;
use InvalidArgumentException;

class AIBackendManager
{
    /**
     * The array of resolved backends.
     *
     * @var array<string, AIBackendInterface>
     */
    protected array $drivers = [];

    /**
     * The registered custom driver creators.
     *
     * @var array<string, Closure>
     */
    protected array $customCreators = [];

    protected ModelConfigNormalizer $normalizer;

    public function __construct()
    {
        $this->normalizer = new ModelConfigNormalizer;
    }

    /**
     * Get a configured backend for a specific agent.
     * This merges global config with agent-specific overrides.
     *
     * @return array{backend: AIBackendInterface, config: NormalizedModelConfig}
     */
    public function forAgent(Agent $agent): array
    {
        $backendName = $agent->ai_backend ?? config('ai.default');
        $backend = $this->driver($backendName);
        $config = $this->normalizer->normalize($agent);

        return [
            'backend' => $backend->withConfig($config),
            'config' => $config,
        ];
    }

    /**
     * Get a backend driver instance.
     */
    public function driver(?string $name = null): AIBackendInterface
    {
        $name = $name ?? config('ai.default');

        if (! isset($this->drivers[$name])) {
            $this->drivers[$name] = $this->createDriver($name);
        }

        return $this->drivers[$name];
    }

    /**
     * Create a new backend driver instance.
     */
    protected function createDriver(string $name): AIBackendInterface
    {
        $config = config("ai.backends.{$name}");

        if ($config === null) {
            throw new InvalidArgumentException("Backend [{$name}] is not defined.");
        }

        // Check for custom creator
        if (isset($this->customCreators[$config['driver']])) {
            return $this->customCreators[$config['driver']]($config);
        }

        // Use built-in drivers
        $method = 'create'.ucfirst($config['driver']).'Driver';

        if (method_exists($this, $method)) {
            return $this->{$method}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] not supported.");
    }

    /**
     * Register a custom driver creator.
     */
    public function extend(string $driver, Closure $callback): void
    {
        $this->customCreators[$driver] = $callback;
    }

    /**
     * Create an Ollama driver instance.
     */
    protected function createOllamaDriver(array $config): AIBackendInterface
    {
        return new OllamaBackend($config);
    }

    /**
     * Create an Anthropic driver instance.
     */
    protected function createAnthropicDriver(array $config): AIBackendInterface
    {
        return new AnthropicBackend($config);
    }

    /**
     * Create an OpenAI driver instance.
     */
    protected function createOpenaiDriver(array $config): AIBackendInterface
    {
        return new OpenAIBackend($config);
    }

    /**
     * Create a HuggingFace driver instance.
     */
    protected function createHuggingfaceDriver(array $config): AIBackendInterface
    {
        return new HuggingFaceBackend($config);
    }

    /**
     * Create a vLLM driver instance.
     */
    protected function createVllmDriver(array $config): AIBackendInterface
    {
        return new VLLMBackend($config);
    }

    /**
     * Get all registered driver names.
     *
     * @return array<int, string>
     */
    public function getDrivers(): array
    {
        return array_keys(config('ai.backends', []));
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return config('ai.default');
    }
}
