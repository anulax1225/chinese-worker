<?php

namespace App\DTOs;

readonly class AIModel
{
    public function __construct(
        public string $name,
        public ?string $modifiedAt = null,
        public ?int $size = null,
        public ?string $digest = null,
        public ?string $family = null,
        public ?string $parameterSize = null,
        public ?string $quantizationLevel = null,
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

        return new self(
            name: $data['modelfile'] ?? $data['model'] ?? 'unknown',
            modifiedAt: $data['modified_at'] ?? null,
            size: null, // Not provided in show response
            digest: null,
            family: $details['family'] ?? null,
            parameterSize: $details['parameter_size'] ?? null,
            quantizationLevel: $details['quantization_level'] ?? null,
            details: array_merge($details, [
                'template' => $data['template'] ?? null,
                'parameters' => $data['parameters'] ?? null,
                'model_info' => $modelInfo,
                'license' => $data['license'] ?? null,
            ]),
        );
    }
}
