<?php

namespace App\Services\BuiltinTools;

use App\DTOs\ToolResult;

class ReadTool extends AbstractBuiltinTool
{
    public function getName(): string
    {
        return 'read';
    }

    public function getDescription(): string
    {
        return 'Read the contents of a file. Returns the file content with line numbers.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'The absolute path to the file to read',
                ],
                'offset' => [
                    'type' => 'integer',
                    'description' => 'The line number to start reading from (1-indexed). Optional.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'description' => 'The maximum number of lines to read. Optional.',
                ],
            ],
            'required' => ['file_path'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $filePath = $this->normalizePath($arguments['file_path']);
        $offset = max(1, (int) ($arguments['offset'] ?? 1));
        $limit = (int) ($arguments['limit'] ?? config('agent.file.max_read_lines', 2000));

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

        // Check file size
        $maxSize = config('agent.file.max_file_size', 10 * 1024 * 1024);
        if (filesize($filePath) > $maxSize) {
            return ToolResult::failure('File too large. Maximum size is '.($maxSize / 1024 / 1024).'MB');
        }

        // Check if readable
        if (! is_readable($filePath)) {
            return ToolResult::failure("Cannot read file: {$filePath}");
        }

        try {
            $content = $this->readFileWithLineNumbers($filePath, $offset, $limit);

            return ToolResult::success($content, [
                'file_path' => $filePath,
                'offset' => $offset,
                'limit' => $limit,
            ]);
        } catch (\Exception $e) {
            return ToolResult::failure("Error reading file: {$e->getMessage()}");
        }
    }

    /**
     * Read a file and add line numbers.
     */
    protected function readFileWithLineNumbers(string $filePath, int $offset, int $limit): string
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: {$filePath}");
        }

        $output = [];
        $lineNumber = 0;
        $linesRead = 0;
        $format = config('agent.file.line_number_format', '%6d | %s');

        while (($line = fgets($handle)) !== false) {
            $lineNumber++;

            // Skip lines before offset
            if ($lineNumber < $offset) {
                continue;
            }

            // Stop if we've read enough lines
            if ($linesRead >= $limit) {
                $output[] = sprintf('... (truncated at %d lines)', $limit);
                break;
            }

            // Remove trailing newline and format with line number
            $line = rtrim($line, "\r\n");

            // Truncate very long lines
            if (strlen($line) > 2000) {
                $line = substr($line, 0, 2000).'... (line truncated)';
            }

            $output[] = sprintf($format, $lineNumber, $line);
            $linesRead++;
        }

        fclose($handle);

        if (empty($output)) {
            return '(empty file)';
        }

        return implode("\n", $output);
    }

    public function validate(array $arguments): array
    {
        $errors = parent::validate($arguments);

        if (isset($arguments['offset']) && $arguments['offset'] < 1) {
            $errors[] = 'Offset must be at least 1';
        }

        if (isset($arguments['limit']) && $arguments['limit'] < 1) {
            $errors[] = 'Limit must be at least 1';
        }

        return $errors;
    }
}
