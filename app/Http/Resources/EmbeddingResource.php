<?php

namespace App\Http\Resources;

use App\Enums\EmbeddingStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmbeddingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'embedding' => $this->when($this->status === EmbeddingStatus::Completed, $this->embedding_raw),
            'model' => $this->model,
            'dimensions' => $this->when($this->status === EmbeddingStatus::Completed, $this->dimensions),
            'status' => $this->status->value,
            'error' => $this->when($this->status === EmbeddingStatus::Failed, $this->error),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
