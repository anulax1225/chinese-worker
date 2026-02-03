<?php

namespace App\DTOs;

class ToolCall
{
    /**
     * Create a new tool call instance.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments = []
    ) {}

    /**
     * Convert the tool call to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'call_id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
