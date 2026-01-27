<?php

namespace App\Services\BuiltinTools;

use App\DTOs\ToolResult;

class WriteTool extends AbstractBuiltinTool
{
    public function getName(): string
    {
        return 'write';
    }

    public function getDescription(): string
    {
        return 'Write content to a file. Creates the file if it does not exist, or overwrites it if it does.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to write',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The content to write to the file',
                ],
            ],
            'required' => ['file_path', 'content'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $filePath = $this->normalizePath($arguments['file_path']);
        $content = $arguments['content'];

        // Validate path
        if (! $this->isPathAllowed($filePath)) {
            return ToolResult::failure("Access denied: {$filePath} is not within allowed paths");
        }

        // Check if file exists and warn
        $fileExists = file_exists($filePath);

        // Create directory if it doesn't exist
        $directory = dirname($filePath);
        if (! is_dir($directory)) {
            if (! $this->isPathAllowed($directory)) {
                return ToolResult::failure("Access denied: Cannot create directory {$directory}");
            }

            try {
                mkdir($directory, 0755, true);
            } catch (\Exception $e) {
                return ToolResult::failure("Cannot create directory: {$e->getMessage()}");
            }
        }

        // Check if directory is writable
        if (! is_writable($directory)) {
            return ToolResult::failure("Cannot write to directory: {$directory}");
        }

        // Check file size before writing
        $maxSize = config('agent.file.max_file_size', 10 * 1024 * 1024);
        if (strlen($content) > $maxSize) {
            return ToolResult::failure('Content too large. Maximum size is '.($maxSize / 1024 / 1024).'MB');
        }

        try {
            $bytesWritten = file_put_contents($filePath, $content);

            if ($bytesWritten === false) {
                return ToolResult::failure("Failed to write to file: {$filePath}");
            }

            $action = $fileExists ? 'Updated' : 'Created';

            return ToolResult::success(
                "{$action} file: {$filePath} ({$bytesWritten} bytes written)",
                [
                    'file_path' => $filePath,
                    'bytes_written' => $bytesWritten,
                    'action' => strtolower($action),
                ]
            );
        } catch (\Exception $e) {
            return ToolResult::failure("Error writing file: {$e->getMessage()}");
        }
    }
}
