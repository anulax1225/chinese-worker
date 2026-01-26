<?php

namespace App\Services;

use App\Models\Tool;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Process;

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
            'command' => $this->executeCommandTool($tool, $params),
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

    /**
     * Execute a command tool.
     */
    protected function executeCommandTool(Tool $tool, array $params): mixed
    {
        $config = $tool->config;
        $command = $config['command'];

        // Replace placeholders with params
        foreach ($params as $key => $value) {
            $command = str_replace("{{$key}}", escapeshellarg($value), $command);
        }

        $result = Process::run($command);

        return [
            'output' => $result->output(),
            'error' => $result->errorOutput(),
            'exit_code' => $result->exitCode(),
        ];
    }
}
