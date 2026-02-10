<?php

namespace App\DTOs;

readonly class AIModel
{
    /**
     * @param  array<int, string>  $capabilities
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public string $name,
        public ?string $modifiedAt = null,
        public ?int $size = null,
        public ?string $digest = null,
        public ?string $family = null,
        public ?string $parameterSize = null,
        public ?string $quantizationLevel = null,
        public array $capabilities = [],
        public ?int $contextLength = null,
        public ?string $description = null,
        public array $details = [],
    ) {}

    public function getSizeForHumans(): ?string
    {
        if ($this->size === null) {
            return null;
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $this->size;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return round($size, 2).' '.$units[$unitIndex];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'modified_at' => $this->modifiedAt,
            'size' => $this->size,
            'size_human' => $this->getSizeForHumans(),
            'digest' => $this->digest,
            'family' => $this->family,
            'parameter_size' => $this->parameterSize,
            'quantization_level' => $this->quantizationLevel,
            'capabilities' => $this->capabilities,
            'context_length' => $this->contextLength,
            'description' => $this->description,
            'details' => $this->details,
        ];
    }

    /**
     * Create from Ollama /api/tags response item.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromOllamaTag(array $data): self
    {
        $details = $data['details'] ?? [];

        return new self(
            name: $data['name'] ?? $data['model'] ?? 'unknown',
            modifiedAt: $data['modified_at'] ?? null,
            size: $data['size'] ?? null,
            digest: $data['digest'] ?? null,
            family: $details['family'] ?? null,
            parameterSize: $details['parameter_size'] ?? null,
            quantizationLevel: $details['quantization_level'] ?? null,
            capabilities: [],
            contextLength: null,
            description: null,
            details: $details,
        );
    }

    /**
     * Create from Ollama /api/show response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromOllamaShow(array $data): self
    {
        $details = $data['details'] ?? [];
        $modelInfo = $data['model_info'] ?? [];
        $family = $details['family'] ?? null;

        return new self(
            name: $data['modelfile'] ?? $data['model'] ?? 'unknown',
            modifiedAt: $data['modified_at'] ?? null,
            size: null, // Not provided in show response
            digest: null,
            family: $family,
            parameterSize: $details['parameter_size'] ?? null,
            quantizationLevel: $details['quantization_level'] ?? null,
            capabilities: $data['capabilities'] ?? [],
            contextLength: self::extractContextLength($modelInfo, $family),
            description: null,
            details: array_merge($details, [
                'template' => $data['template'] ?? null,
                'parameters' => $data['parameters'] ?? null,
                'model_info' => $modelInfo,
                'license' => $data['license'] ?? null,
            ]),
        );
    }

    /**
     * Create from Ollama /api/show response merged with /api/tags data.
     *
     * @param  array<string, mixed>  $showData  Response from /api/show
     * @param  array<string, mixed>  $tagData  Response item from /api/tags
     */
    public static function fromOllamaShowWithTag(array $showData, array $tagData): self
    {
        $details = $showData['details'] ?? [];
        $modelInfo = $showData['model_info'] ?? [];
        $family = $details['family'] ?? null;

        return new self(
            name: $tagData['name'] ?? $showData['model'] ?? 'unknown',
            modifiedAt: $showData['modified_at'] ?? $tagData['modified_at'] ?? null,
            size: $tagData['size'] ?? null,
            digest: $tagData['digest'] ?? null,
            family: $family,
            parameterSize: $details['parameter_size'] ?? null,
            quantizationLevel: $details['quantization_level'] ?? null,
            capabilities: $showData['capabilities'] ?? [],
            contextLength: self::extractContextLength($modelInfo, $family),
            description: null,
            details: array_merge($details, [
                'format' => $details['format'] ?? null,
                'families' => $details['families'] ?? [],
            ]),
        );
    }

    /**
     * Create from vLLM /v1/models response item.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromVLLM(array $data): self
    {
        $modelId = $data['id'] ?? 'unknown';

        return new self(
            name: $modelId,
            modifiedAt: isset($data['created']) ? date('c', $data['created']) : null,
            size: null,
            digest: null,
            family: self::extractFamilyFromModelId($modelId),
            parameterSize: null,
            quantizationLevel: null,
            capabilities: ['completion', 'tool_use'],
            contextLength: self::getKnownContextLength($modelId),
            description: null,
            details: [
                'source' => 'self_hosted',
                'object' => $data['object'] ?? 'model',
                'owned_by' => $data['owned_by'] ?? 'vllm',
            ],
        );
    }

    /**
     * Create from vLLM manager /api/tags response item (Ollama-compatible format).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromVLLMTag(array $data): self
    {
        $details = $data['details'] ?? [];
        $modelName = $data['name'] ?? $data['model'] ?? 'unknown';

        return new self(
            name: $modelName,
            modifiedAt: $data['modified_at'] ?? null,
            size: $data['size'] ?? null,
            digest: $data['digest'] ?? null,
            family: $details['family'] ?? self::extractFamilyFromModelId($modelName),
            parameterSize: $details['parameter_size'] ?? null,
            quantizationLevel: $details['quantization_level'] ?? null,
            capabilities: ['completion', 'tool_use'],
            contextLength: self::getKnownContextLength($modelName),
            description: null,
            details: array_merge($details, [
                'source' => 'self_hosted',
                'backend' => 'vllm',
            ]),
        );
    }

    /**
     * Create from vLLM manager /api/show response (Ollama-compatible format).
     *
     * @param  array<string, mixed>  $data
     * @param  string|null  $modelName  Optional model name override
     */
    public static function fromVLLMShow(array $data, ?string $modelName = null): self
    {
        $details = $data['details'] ?? [];
        $modelInfo = $data['model_info'] ?? [];
        $name = $modelName ?? $data['name'] ?? $data['model'] ?? 'unknown';

        return new self(
            name: $name,
            modifiedAt: $data['modified_at'] ?? null,
            size: null, // Not provided in show response
            digest: null,
            family: $details['family'] ?? self::extractFamilyFromModelId($name),
            parameterSize: $details['parameter_size'] ?? null,
            quantizationLevel: $details['quantization_level'] ?? null,
            capabilities: ['completion', 'tool_use'],
            contextLength: $modelInfo['context_length'] ?? self::getKnownContextLength($name),
            description: null,
            details: [
                'source' => 'self_hosted',
                'backend' => 'vllm',
                'format' => $details['format'] ?? null,
                'families' => $details['families'] ?? [],
                'architecture' => $modelInfo['general.architecture'] ?? null,
                'hidden_size' => $modelInfo['hidden_size'] ?? null,
                'num_layers' => $modelInfo['num_layers'] ?? null,
                'vocab_size' => $modelInfo['vocab_size'] ?? null,
                'parameters' => $data['parameters'] ?? null,
            ],
        );
    }

    /**
     * Extract model family from model ID (e.g., "meta-llama/Llama-3.1-8B" -> "llama").
     */
    private static function extractFamilyFromModelId(string $modelId): ?string
    {
        $modelLower = strtolower($modelId);

        return match (true) {
            str_contains($modelLower, 'llama') => 'llama',
            str_contains($modelLower, 'qwen') => 'qwen',
            str_contains($modelLower, 'mistral') || str_contains($modelLower, 'mixtral') => 'mistral',
            str_contains($modelLower, 'phi') => 'phi',
            str_contains($modelLower, 'gemma') => 'gemma',
            str_contains($modelLower, 'deepseek') => 'deepseek',
            str_contains($modelLower, 'hermes') => 'hermes',
            default => null,
        };
    }

    /**
     * Get known context length for popular models.
     */
    private static function getKnownContextLength(string $modelId): ?int
    {
        $knownLimits = [
            'meta-llama/Llama-3.1' => 131072,
            'meta-llama/Llama-3.2' => 131072,
            'Qwen/Qwen2.5' => 131072,
            'Qwen/Qwen3' => 131072,
            'microsoft/phi-4' => 16384,
            'mistralai/Mistral' => 32768,
            'deepseek-ai/DeepSeek' => 65536,
            'NousResearch/Hermes' => 131072,
        ];

        foreach ($knownLimits as $prefix => $contextLength) {
            if (str_starts_with($modelId, $prefix)) {
                return $contextLength;
            }
        }

        return null;
    }

    /**
     * Extract context length from model_info.
     *
     * @param  array<string, mixed>  $modelInfo
     */
    private static function extractContextLength(array $modelInfo, ?string $family): ?int
    {
        if (empty($modelInfo)) {
            return null;
        }

        // Try family-specific context length (e.g., gemma3.context_length)
        if ($family !== null) {
            $familyKey = "{$family}.context_length";
            if (isset($modelInfo[$familyKey])) {
                return (int) $modelInfo[$familyKey];
            }
        }

        // Try general context length
        if (isset($modelInfo['general.context_length'])) {
            return (int) $modelInfo['general.context_length'];
        }

        return null;
    }
}
