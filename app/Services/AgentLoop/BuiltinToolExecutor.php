<?php

namespace App\Services\AgentLoop;

use App\Contracts\BuiltinToolInterface;
use App\DTOs\ToolCall;
use App\DTOs\ToolResult;
use App\Models\Tool;
use App\Services\BuiltinTools\BashTool;
use App\Services\BuiltinTools\EditTool;
use App\Services\BuiltinTools\GlobTool;
use App\Services\BuiltinTools\GrepTool;
use App\Services\BuiltinTools\ReadTool;
use App\Services\BuiltinTools\WriteTool;
use App\Services\ToolService;
use Illuminate\Support\Facades\Log;

class BuiltinToolExecutor
{
    /**
     * Registry of builtin tools.
     *
     * @var array<string, BuiltinToolInterface>
     */
    protected array $builtinTools = [];

    public function __construct(protected ToolService $toolService)
    {
        $this->registerBuiltinTools();
    }

    /**
     * Register all builtin tools.
     */
    protected function registerBuiltinTools(): void
    {
        $tools = [
            new ReadTool,
            new WriteTool,
            new EditTool,
            new GlobTool,
            new GrepTool,
            new BashTool,
        ];

        foreach ($tools as $tool) {
            $this->builtinTools[$tool->getName()] = $tool;
        }
    }

    /**
     * Get all registered builtin tools.
     *
     * @return array<string, BuiltinToolInterface>
     */
    public function getBuiltinTools(): array
    {
        return $this->builtinTools;
    }

    /**
     * Check if a tool name is a builtin tool.
     */
    public function isBuiltinTool(string $name): bool
    {
        return isset($this->builtinTools[$name]);
    }

    /**
     * Execute a tool call.
     *
     * @param  array<Tool>  $agentTools  The tools attached to the agent
     */
    public function execute(ToolCall $toolCall, array $agentTools = []): ToolResult
    {
        $toolName = $toolCall->name;
        $arguments = $toolCall->arguments;

        Log::info("Executing tool: {$toolName}", ['arguments' => $arguments]);

        // Check if it's a builtin tool
        if ($this->isBuiltinTool($toolName)) {
            return $this->executeBuiltinTool($toolName, $arguments);
        }

        // Try to find the tool in the agent's tools
        $tool = $this->findAgentTool($toolName, $agentTools);

        if ($tool === null) {
            return ToolResult::failure("Unknown tool: {$toolName}");
        }

        // Execute using the existing ToolService
        return $this->executeAgentTool($tool, $arguments);
    }

    /**
     * Execute a builtin tool.
     */
    protected function executeBuiltinTool(string $name, array $arguments): ToolResult
    {
        $tool = $this->builtinTools[$name];

        // Validate arguments
        $errors = $tool->validate($arguments);
        if (! empty($errors)) {
            return ToolResult::failure('Validation failed: '.implode(', ', $errors));
        }

        try {
            return $tool->execute($arguments);
        } catch (\Exception $e) {
            Log::error("Builtin tool execution failed: {$name}", [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            return ToolResult::failure("Tool execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Find a tool in the agent's attached tools.
     *
     * @param  array<Tool>  $agentTools
     */
    protected function findAgentTool(string $name, array $agentTools): ?Tool
    {
        foreach ($agentTools as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * Execute an agent tool using the ToolService.
     */
    protected function executeAgentTool(Tool $tool, array $arguments): ToolResult
    {
        try {
            $result = $this->toolService->execute($tool, $arguments);

            // Convert to ToolResult
            if (is_string($result)) {
                return ToolResult::success($result);
            }

            if (is_array($result)) {
                return ToolResult::success(json_encode($result, JSON_PRETTY_PRINT));
            }

            return ToolResult::success((string) $result);
        } catch (\Exception $e) {
            Log::error("Agent tool execution failed: {$tool->name}", [
                'error' => $e->getMessage(),
                'arguments' => $arguments,
            ]);

            return ToolResult::failure("Tool execution failed: {$e->getMessage()}");
        }
    }

    /**
     * Get the parameter schema for a tool.
     *
     * @param  array<Tool>  $agentTools
     * @return array<string, mixed>|null
     */
    public function getToolSchema(string $name, array $agentTools = []): ?array
    {
        if ($this->isBuiltinTool($name)) {
            $tool = $this->builtinTools[$name];

            return [
                'type' => 'function',
                'function' => [
                    'name' => $tool->getName(),
                    'description' => $tool->getDescription(),
                    'parameters' => $tool->getParameterSchema(),
                ],
            ];
        }

        $tool = $this->findAgentTool($name, $agentTools);

        if ($tool === null) {
            return null;
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $tool->name,
                'description' => $tool->config['description'] ?? "Execute {$tool->name}",
                'parameters' => $tool->config['parameters'] ?? [
                    'type' => 'object',
                    'properties' => [],
                ],
            ],
        ];
    }

    /**
     * Get all available tools as schemas for the AI.
     *
     * @param  array<Tool>  $agentTools
     * @return array<array<string, mixed>>
     */
    public function getAllToolSchemas(array $agentTools = [], bool $includeBuiltins = true): array
    {
        $schemas = [];

        // Add builtin tools
        if ($includeBuiltins) {
            foreach ($this->builtinTools as $tool) {
                $schemas[] = [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool->getName(),
                        'description' => $tool->getDescription(),
                        'parameters' => $tool->getParameterSchema(),
                    ],
                ];
            }
        }

        // Add agent tools
        foreach ($agentTools as $tool) {
            // Skip builtin type tools (they're already added)
            if ($tool->type === 'builtin') {
                continue;
            }

            $schemas[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->config['description'] ?? "Execute {$tool->name}",
                    'parameters' => $tool->config['parameters'] ?? [
                        'type' => 'object',
                        'properties' => [],
                    ],
                ],
            ];
        }

        return $schemas;
    }
}
