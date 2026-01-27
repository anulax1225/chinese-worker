<?php

namespace App\Contracts;

use App\DTOs\ToolResult;

interface BuiltinToolInterface
{
    /**
     * Get the tool name.
     */
    public function getName(): string;

    /**
     * Get the tool description.
     */
    public function getDescription(): string;

    /**
     * Get the parameter schema for this tool.
     *
     * @return array<string, mixed>
     */
    public function getParameterSchema(): array;

    /**
     * Execute the tool with the given arguments.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function execute(array $arguments): ToolResult;

    /**
     * Validate the arguments before execution.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string> List of validation errors, empty if valid
     */
    public function validate(array $arguments): array;
}
