<?php

namespace App\Services;

use App\Models\Tool;
use GuzzleHttp\Client;

class ToolService
{
    /**
     * Create a new tool.
     */
    public function create(array $data): Tool
    {
        return Tool::query()->create($data);
    }

    /**
     * Update an existing tool.
     */
    public function update(Tool $tool, array $data): Tool
    {
        $tool->update($data);

        return $tool->fresh();
    }

    /**
     * Delete a tool.
     */
    public function delete(Tool $tool): bool
    {
        return $tool->delete();
    }

    /**
     * Execute a tool with the given parameters.
     */
    public function execute(Tool $tool, array $params): mixed
    {
        return match ($tool->type) {
            'api' => $this->executeApiTool($tool, $params),
            'function' => $this->executeFunctionTool($tool, $params),
            default => throw new \InvalidArgumentException("Unsupported tool type: {$tool->type}"),
        };
    }

    /**
     * Execute an API tool.
     */
    protected function executeApiTool(Tool $tool, array $params): mixed
    {
        $config = $tool->config;
        $client = new Client;

        $response = $client->request(
            $config['method'] ?? 'GET',
            $config['url'],
            [
                'headers' => $config['headers'] ?? [],
                'json' => $params,
            ]
        );

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Execute a function tool.
     */
    protected function executeFunctionTool(Tool $tool, array $params): mixed
    {
        $config = $tool->config;

        // Sandbox evaluation would go here
        // For now, this is a placeholder
        // In production, use a proper sandboxing solution

        return [
            'status' => 'executed',
            'params' => $params,
            'config' => $config,
        ];
    }
}
