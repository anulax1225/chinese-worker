<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentStageResource extends JsonResource
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
            'stage' => $this->stage,
            'content' => $this->when(
                $request->boolean('full_content', false),
                $this->content,
                $this->getPreview()
            ),
            'content_preview' => $this->getPreview(),
            'character_count' => $this->getCharacterCount(),
            'word_count' => $this->getWordCount(),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
