<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageResource extends JsonResource
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
            'position' => $this->position,
            'role' => $this->role,
            'name' => $this->name,
            'content' => $this->content,
            'thinking' => $this->thinking,
            'token_count' => $this->token_count,
            'tool_call_id' => $this->tool_call_id,
            'tool_calls' => MessageToolCallResource::collection($this->whenLoaded('toolCalls')),
            'attachments' => MessageAttachmentResource::collection($this->whenLoaded('attachments')),
            'counted_at' => $this->counted_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * Transform the resource to match the legacy array format.
     *
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        $toolCalls = null;
        if ($this->relationLoaded('toolCalls') && $this->toolCalls->isNotEmpty()) {
            $toolCalls = $this->toolCalls->map(fn ($tc) => [
                'call_id' => $tc->id,
                'name' => $tc->function_name,
                'arguments' => $tc->arguments,
            ])->all();
        }

        $images = null;
        if ($this->relationLoaded('attachments')) {
            $imageAttachments = $this->attachments->where('type', 'image');
            if ($imageAttachments->isNotEmpty()) {
                $images = $imageAttachments->pluck('storage_path')->all();
            }
        }

        return [
            'role' => $this->role,
            'content' => $this->content,
            'tool_calls' => $toolCalls,
            'tool_call_id' => $this->tool_call_id,
            'images' => $images,
            'thinking' => $this->thinking,
            'name' => $this->name,
            'token_count' => $this->token_count,
            'counted_at' => $this->counted_at?->toISOString(),
        ];
    }
}
