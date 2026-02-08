<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentChunkResource extends JsonResource
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
            'document_id' => $this->document_id,
            'chunk_index' => $this->chunk_index,
            'content' => $this->content,
            'token_count' => $this->token_count,
            'start_offset' => $this->start_offset,
            'end_offset' => $this->end_offset,
            'section_title' => $this->section_title,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
