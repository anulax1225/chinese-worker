<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
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
            'agent_id' => $this->agent_id,
            'user_id' => $this->user_id,
            'agent' => $this->whenLoaded('agent'),
            'user' => $this->whenLoaded('user'),
            'status' => $this->status,
            'messages' => $this->messages,
            'metadata' => $this->metadata,
            'turn_count' => $this->turn_count,
            'total_tokens' => $this->total_tokens,
            'started_at' => $this->started_at,
            'last_activity_at' => $this->last_activity_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
