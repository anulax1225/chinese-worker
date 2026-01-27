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
     * Create a ToolCall from an Ollama tool call response.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromOllama(array $data): self
    {
        return new self(
            id: $data['id'] ?? uniqid('tool_'),
            name: $data['function']['name'] ?? '',
            arguments: $data['function']['arguments'] ?? []
        );
    }

    /**
     * Convert the tool call to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'arguments' => $this->arguments,
        ];
    }
}
