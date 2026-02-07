<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AgentResource extends JsonResource
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
            'user' => $this->whenLoaded('user'),
            'name' => $this->name,
            'description' => $this->description,
            'code' => $this->code,
            'config' => $this->config,
            'model_config' => $this->model_config,
            'status' => $this->status,
            'ai_backend' => $this->ai_backend,
            'context_variables' => $this->context_variables,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'system_prompts' => SystemPromptResource::collection($this->whenLoaded('systemPrompts')),
        ];
    }
}
