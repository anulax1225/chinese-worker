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
