<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\ConversationSummary
 */
class ConversationSummaryResource extends JsonResource
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
            'conversation_id' => $this->conversation_id,
            'status' => $this->status?->value,
            'from_position' => $this->from_position,
            'to_position' => $this->to_position,
            'content' => $this->when($this->status?->isComplete(), $this->content),
            'token_count' => $this->token_count,
            'original_token_count' => $this->original_token_count,
            'compression_ratio' => $this->when(
                $this->status?->isComplete(),
                fn () => $this->getCompressionRatio()
            ),
            'tokens_saved' => $this->when(
                $this->status?->isComplete(),
                fn () => $this->getTokensSaved()
            ),
            'backend_used' => $this->backend_used,
            'model_used' => $this->model_used,
            'error_message' => $this->when($this->status?->isFailed(), $this->error_message),
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
