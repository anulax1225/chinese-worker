<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageAttachmentResource extends JsonResource
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
            'type' => $this->type,
            'document_id' => $this->document_id,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'storage_path' => $this->storage_path,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
