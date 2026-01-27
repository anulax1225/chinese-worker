<?php

namespace App\Services\BuiltinTools;

use App\DTOs\ToolResult;

class EditTool extends AbstractBuiltinTool
{
    public function getName(): string
    {
        return 'edit';
    }

    public function getDescription(): string
    {
        return 'Perform exact string replacement in a file. The old_string must be unique in the file.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to edit',
                ],
                'old_string' => [
                    'type' => 'string',
                    'description' => 'The exact text to replace (must be unique in the file)',
                ],
                'new_string' => [
                    'type' => 'string',
                    'description' => 'The text to replace it with',
                ],
                'replace_all' => [
                    'type' => 'boolean',
                    'description' => 'Replace all occurrences instead of requiring uniqueness. Default: false',
                ],
            ],
            'required' => ['file_path', 'old_string', 'new_string'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $filePath = $this->normalizePath($arguments['file_path']);
        $oldString = $arguments['old_string'];
        $newString = $arguments['new_string'];
        $replaceAll = (bool) ($arguments['replace_all'] ?? false);

        // Validate path
        if (! $this->isPathAllowed($filePath)) {
            return ToolResult::failure("Access denied: {$filePath} is not within allowed paths");
        }

        // Check if file exists
        if (! file_exists($filePath)) {
            return ToolResult::failure("File not found: {$filePath}");
        }

        // Check if it's a file
        if (! is_file($filePath)) {
            return ToolResult::failure("{$filePath} is not a file");
        }

        // Check if readable and writable
        if (! is_readable($filePath) || ! is_writable($filePath)) {
            return ToolResult::failure("Cannot read/write file: {$filePath}");
        }

        // Check file size
        $maxSize = config('agent.file.max_file_size', 10 * 1024 * 1024);
        if (filesize($filePath) > $maxSize) {
            return ToolResult::failure('File too large. Maximum size is '.($maxSize / 1024 / 1024).'MB');
        }

        // Validate old_string and new_string are different
        if ($oldString === $newString) {
            return ToolResult::failure('old_string and new_string must be different');
        }

        try {
            $content = file_get_contents($filePath);

            if ($content === false) {
                return ToolResult::failure("Cannot read file: {$filePath}");
            }

            // Count occurrences
            $occurrences = substr_count($content, $oldString);

            if ($occurrences === 0) {
                return ToolResult::failure('old_string not found in file. Make sure to match the exact text including whitespace and indentation.');
            }

            if ($occurrences > 1 && ! $replaceAll) {
                return ToolResult::failure(
                    "old_string is not unique - found {$occurrences} occurrences. ".
                    'Either provide a larger context to make it unique, or set replace_all to true.'
                );
            }

            // Perform replacement
            if ($replaceAll) {
                $newContent = str_replace($oldString, $newString, $content);
                $replacements = $occurrences;
            } else {
                // Replace only first occurrence (which is the only one due to check above)
                $pos = strpos($content, $oldString);
                $newContent = substr_replace($content, $newString, $pos, strlen($oldString));
                $replacements = 1;
            }

            // Write back
            $bytesWritten = file_put_contents($filePath, $newContent);

            if ($bytesWritten === false) {
                return ToolResult::failure("Failed to write file: {$filePath}");
            }

            $message = $replaceAll
                ? "Replaced {$replacements} occurrence(s) in {$filePath}"
                : "Successfully edited {$filePath}";

            return ToolResult::success($message, [
                'file_path' => $filePath,
                'replacements' => $replacements,
                'bytes_written' => $bytesWritten,
            ]);
        } catch (\Exception $e) {
            return ToolResult::failure("Error editing file: {$e->getMessage()}");
        }
    }

    public function validate(array $arguments): array
    {
        $errors = parent::validate($arguments);

        if (isset($arguments['old_string']) && $arguments['old_string'] === '') {
            $errors[] = 'old_string cannot be empty';
        }

        return $errors;
    }
}
