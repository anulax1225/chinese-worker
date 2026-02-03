<?php

namespace App\Services;

use App\Models\Conversation;
use Illuminate\Support\Facades\Log;

class ToolSchemaRegistry
{
    /**
     * Get schemas for all system tools (server-executed, always available).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSystemToolSchemas(): array
    {
        return [
            [
                'name' => 'todo_add',
                'description' => 'Add a new todo item for this agent',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'item' => [
                            'type' => 'string',
                            'description' => 'The todo item description',
                        ],
                        'priority' => [
                            'type' => 'string',
                            'description' => 'Priority level: low, medium, high',
                            'enum' => ['low', 'medium', 'high'],
                        ],
                    ],
                    'required' => ['item'],
                ],
            ],
            [
                'name' => 'todo_list',
                'description' => 'List all todo items for this agent',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            [
                'name' => 'todo_complete',
                'description' => 'Mark a todo item as complete',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'The todo item ID',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'todo_update',
                'description' => 'Update a todo item',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'The todo item ID',
                        ],
                        'item' => [
                            'type' => 'string',
                            'description' => 'Updated todo description',
                        ],
                    ],
                    'required' => ['id', 'item'],
                ],
            ],
            [
                'name' => 'todo_delete',
                'description' => 'Delete a todo item',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => [
                            'type' => 'integer',
                            'description' => 'The todo item ID to delete',
                        ],
                    ],
                    'required' => ['id'],
                ],
            ],
            [
                'name' => 'todo_clear',
                'description' => 'Clear all todo items for this agent',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            [
                'name' => 'web_search',
                'description' => 'Search the web for information',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum number of results (default: 5)',
                        ],
                    ],
                    'required' => ['query'],
                ],
            ],
        ];
    }

    /**
     * Get all tool schemas for a conversation.
     *
     * Combines: client tools (from conversation) + system tools + user tools (from agent).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getToolsForConversation(Conversation $conversation): array
    {
        $tools = [];

        // Add client tools (schemas sent by client, stored in conversation)
        $clientToolSchemas = $conversation->client_tool_schemas ?? [];

        Log::info('ToolSchemaRegistry: aggregating tools', [
            'conversation_id' => $conversation->id,
            'client_tools_count' => count($clientToolSchemas),
            'client_tool_names' => array_column($clientToolSchemas, 'name'),
            'system_tools_count' => count($this->getSystemToolSchemas()),
        ]);

        $tools = array_merge($tools, $clientToolSchemas);

        // Add all system tools (always available, executed on server)
        $tools = array_merge($tools, $this->getSystemToolSchemas());

        // Add user tools from agent (API tools only for now)
        foreach ($conversation->agent->tools as $tool) {
            if ($tool->type === 'api') {
                $tools[] = [
                    'name' => $tool->name,
                    'description' => $tool->config['description'] ?? '',
                    'parameters' => $tool->config['parameters'] ?? [],
                ];
            }
        }

        return $tools;
    }
}
