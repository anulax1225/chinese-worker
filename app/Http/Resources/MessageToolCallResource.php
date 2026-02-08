<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MessageToolCallResource extends JsonResource
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
            'call_id' => $this->id, // Alias for legacy compatibility
            'function_name' => $this->function_name,
            'name' => $this->function_name, // Alias for legacy compatibility
            'arguments' => $this->arguments,
            'position' => $this->position,
            'created_at' => $this->created_at,
        ];
    }
}
