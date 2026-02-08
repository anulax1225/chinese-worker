<?php

namespace App\Services;

use App\DTOs\ToolResult;
use App\Models\Tool;
use App\Services\Security\UrlSecurityValidator;
use GuzzleHttp\Client;
use InvalidArgumentException;

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
    public function execute(Tool $tool, array $params): ToolResult
    {
        return match ($tool->type) {
            'api' => $this->executeApiTool($tool, $params),
            'function' => $this->executeFunctionTool($tool, $params),
            default => new ToolResult(
                success: false,
                output: '',
                error: "Unsupported tool type: {$tool->type}"
            ),
        };
    }

    /**
     * Execute an API tool.
     *
     * @throws InvalidArgumentException When URL is blocked by SSRF protection
     */
    protected function executeApiTool(Tool $tool, array $params): ToolResult
    {
        $config = $tool->config;
        $url = $config['url'] ?? '';

        if (empty($url)) {
            return new ToolResult(
                success: false,
                output: '',
                error: 'API tool missing URL configuration'
            );
        }

        // Validate URL security (SSRF protection)
        $validator = UrlSecurityValidator::forApiTools();
        try {
            $validator->validate($url);
        } catch (InvalidArgumentException $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: $e->getMessage()
            );
        }

        $client = new Client;

        try {
            $response = $client->request(
                $config['method'] ?? 'GET',
                $url,
                [
                    'headers' => $config['headers'] ?? [],
                    'json' => $params,
                    'timeout' => $config['timeout'] ?? 30,
                ]
            );

            $body = json_decode($response->getBody()->getContents(), true);

            return new ToolResult(
                success: true,
                output: json_encode($body, JSON_PRETTY_PRINT),
                error: null
            );
        } catch (\Exception $e) {
            return new ToolResult(
                success: false,
                output: '',
                error: "API request failed: {$e->getMessage()}"
            );
        }
    }

    /**
     * Execute a function tool.
     */
    protected function executeFunctionTool(Tool $tool, array $params): ToolResult
    {
        // Function tools are not yet implemented
        // In production, use a proper sandboxing solution
        return new ToolResult(
            success: false,
            output: '',
            error: 'Function tools are not yet supported'
        );
    }
}
