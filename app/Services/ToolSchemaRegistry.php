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
            [
                'name' => 'web_fetch',
                'description' => 'Fetch and extract readable content from a URL. Returns the page title and main text content.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'url' => [
                            'type' => 'string',
                            'description' => 'The URL to fetch (must be http or https)',
                        ],
                    ],
                    'required' => ['url'],
                ],
            ],
            [
                'name' => 'document_list',
                'description' => 'List all documents attached to this conversation with their IDs, status, and statistics',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
            [
                'name' => 'document_info',
                'description' => 'Get detailed information about a specific attached document including sections and metadata',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => [
                            'type' => 'integer',
                            'description' => 'The document ID',
                        ],
                    ],
                    'required' => ['document_id'],
                ],
            ],
            [
                'name' => 'document_get_chunks',
                'description' => 'Get the content of specific chunks from a document by index range (max 10 chunks at a time)',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => [
                            'type' => 'integer',
                            'description' => 'The document ID',
                        ],
                        'start_index' => [
                            'type' => 'integer',
                            'description' => 'The starting chunk index (0-based)',
                        ],
                        'end_index' => [
                            'type' => 'integer',
                            'description' => 'The ending chunk index (inclusive, defaults to start_index)',
                        ],
                    ],
                    'required' => ['document_id', 'start_index'],
                ],
            ],
            [
                'name' => 'document_read_file',
                'description' => 'Read the entire content of a document at once. Best for small to medium documents. For large documents exceeding the token limit, use document_get_chunks instead.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'document_id' => [
                            'type' => 'integer',
                            'description' => 'The document ID',
                        ],
                    ],
                    'required' => ['document_id'],
                ],
            ],
            [
                'name' => 'document_search',
                'description' => 'Search for text within attached documents and return matching chunk previews',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'query' => [
                            'type' => 'string',
                            'description' => 'The search query (minimum 2 characters)',
                        ],
                        'document_id' => [
                            'type' => 'integer',
                            'description' => 'Optional: limit search to a specific document',
                        ],
                        'max_results' => [
                            'type' => 'integer',
                            'description' => 'Maximum results to return (default: 5, max: 10)',
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

        // Add system tools (filter document tools if no documents attached)
        $systemTools = $this->getSystemToolSchemas();

        if (! $conversation->hasDocuments()) {
            $systemTools = array_values(array_filter(
                $systemTools,
                fn ($tool) => ! str_starts_with($tool['name'], 'document_')
            ));
        }

        $tools = array_merge($tools, $systemTools);

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
