<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIBackendResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'],
            'driver' => $this->resource['driver'],
            'is_default' => $this->resource['is_default'],
            'model' => $this->resource['model'] ?? null,
            'status' => $this->resource['status'],
            'capabilities' => $this->resource['capabilities'] ?? [],
            'models' => $this->resource['models'] ?? [],
            'error' => $this->when(isset($this->resource['error']), $this->resource['error'] ?? null),
        ];
    }
}
