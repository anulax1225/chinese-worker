<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentResource extends JsonResource
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
            'user_id' => $this->user_id,
            'file_id' => $this->file_id,
            'title' => $this->title,
            'source_type' => $this->source_type,
            'source_path' => $this->source_path,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'status' => $this->status,
            'error_message' => $this->error_message,
            'metadata' => $this->metadata,
            'chunk_count' => $this->whenCounted('chunks'),
            'file' => new FileResource($this->whenLoaded('file')),
            'processing_started_at' => $this->processing_started_at,
            'processing_completed_at' => $this->processing_completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
