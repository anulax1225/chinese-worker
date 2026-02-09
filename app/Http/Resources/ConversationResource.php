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
            'messages' => $this->relationLoaded('conversationMessages')
                ? MessageResource::collection($this->conversationMessages)
                : [],
            'metadata' => $this->metadata,
            'turn_count' => $this->turn_count,
            'total_tokens' => $this->total_tokens,
            'token_usage' => [
                'prompt_tokens' => $this->prompt_tokens ?? 0,
                'completion_tokens' => $this->completion_tokens ?? 0,
                'total_tokens' => $this->total_tokens ?? 0,
                'context_limit' => $this->context_limit,
                'estimated_context_usage' => $this->estimated_context_usage ?? 0,
                'usage_percentage' => $this->context_limit
                    ? round(($this->estimated_context_usage / $this->context_limit) * 100, 1)
                    : null,
            ],
            'started_at' => $this->started_at,
            'last_activity_at' => $this->last_activity_at,
            'completed_at' => $this->completed_at,
            'cli_session_id' => $this->cli_session_id,
            'waiting_for' => $this->waiting_for,
            'pending_tool_request' => $this->pending_tool_request,
            'client_type' => $this->client_type ?? 'none',
            'client_tool_schemas' => $this->client_tool_schemas,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
