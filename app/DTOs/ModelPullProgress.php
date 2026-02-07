<?php

namespace App\DTOs;

readonly class ModelPullProgress
{
    public function __construct(
        public string $status,
        public ?string $digest = null,
        public ?int $total = null,
        public ?int $completed = null,
        public ?string $error = null,
    ) {}

    public function getPercentage(): ?float
    {
        if ($this->total === null || $this->total === 0) {
            return null;
        }

        return round(($this->completed / $this->total) * 100, 2);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'success' || str_contains($this->status, 'success');
    }

    public function isFailed(): bool
    {
        return $this->error !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'digest' => $this->digest,
            'total' => $this->total,
            'completed' => $this->completed,
            'percentage' => $this->getPercentage(),
            'error' => $this->error,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromOllamaResponse(array $data): self
    {
        return new self(
            status: $data['status'] ?? 'unknown',
            digest: $data['digest'] ?? null,
            total: $data['total'] ?? null,
            completed: $data['completed'] ?? null,
            error: $data['error'] ?? null,
        );
    }
}
