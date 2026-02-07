<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemPromptResource extends JsonResource
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
            'name' => $this->name,
            'slug' => $this->slug,
            'template' => $this->template,
            'required_variables' => $this->required_variables,
            'default_values' => $this->default_values,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'pivot' => $this->whenPivotLoaded('agent_system_prompt', fn () => [
                'order' => $this->pivot->order,
                'variable_overrides' => $this->pivot->variable_overrides,
            ]),
        ];
    }
}
