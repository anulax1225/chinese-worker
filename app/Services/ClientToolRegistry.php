<?php

namespace App\Services;

use App\Models\Conversation;

class ClientToolRegistry
{
    /**
     * Get the tool names supported by the client for this conversation.
     *
     * @return array<int, string>
     */
    public function getClientToolNames(Conversation $conversation): array
    {
        $schemas = $conversation->client_tool_schemas ?? [];

        return array_column($schemas, 'name');
    }

    /**
     * Check if the client supports a specific tool.
     */
    public function clientSupports(Conversation $conversation, string $toolName): bool
    {
        return in_array($toolName, $this->getClientToolNames($conversation), true);
    }
}
