<?php

namespace App\Services\BuiltinTools;

use App\DTOs\ToolResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GrepTool extends AbstractBuiltinTool
{
    public function getName(): string
    {
        return 'grep';
    }

    public function getDescription(): string
    {
        return 'Search for a pattern in file contents. Returns matching lines with file paths and line numbers.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The regex pattern to search for',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The file or directory to search in. Defaults to the project root.',
                ],
                'glob' => [
                    'type' => 'string',
                    'description' => 'File pattern to filter (e.g., "*.php", "*.{ts,tsx}")',
                ],
                'case_insensitive' => [
                    'type' => 'boolean',
                    'description' => 'Make the search case-insensitive. Default: false',
                ],
                'context_lines' => [
                    'type' => 'integer',
                    'description' => 'Number of context lines before and after each match. Default: 0',
                ],
            ],
            'required' => ['pattern'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $pattern = $arguments['pattern'];
        $basePath = isset($arguments['path'])
            ? $this->normalizePath($arguments['path'])
            : config('agent.search.default_glob_path', base_path());
        $glob = $arguments['glob'] ?? null;
        $caseInsensitive = (bool) ($arguments['case_insensitive'] ?? false);
        $contextLines = max(0, min(10, (int) ($arguments['context_lines'] ?? 0)));

        // Validate path
        if (! $this->isPathAllowed($basePath)) {
            return ToolResult::failure("Access denied: {$basePath} is not within allowed paths");
        }

        // Check if path exists
        if (! file_exists($basePath)) {
            return ToolResult::failure("Path not found: {$basePath}");
        }

        // Build regex
        $flags = $caseInsensitive ? 'i' : '';
        try {
            $regex = '/'.$pattern.'/'.$flags;
            // Test if regex is valid
            preg_match($regex, '');
            if (preg_last_error() !== PREG_NO_ERROR) {
                return ToolResult::failure('Invalid regex pattern: '.preg_last_error_msg());
            }
        } catch (\Exception $e) {
            return ToolResult::failure("Invalid regex pattern: {$e->getMessage()}");
        }

        try {
            $matches = [];
            $maxResults = config('agent.search.max_results', 1000);
            $matchCount = 0;

            if (is_file($basePath)) {
                $this->searchFile($basePath, $regex, $matches, $contextLines, $maxResults, $matchCount);
            } else {
                $this->searchDirectory($basePath, $regex, $glob, $matches, $contextLines, $maxResults, $matchCount);
            }

            if (empty($matches)) {
                return ToolResult::success("No matches found for pattern: {$pattern}", [
                    'pattern' => $pattern,
                    'path' => $basePath,
                    'count' => 0,
                ]);
            }

            $truncated = $matchCount >= $maxResults;
            $output = implode("\n\n", $matches);

            if ($truncated) {
                $output .= "\n\n... (truncated at {$maxResults} matches)";
            }

            return ToolResult::success($output, [
                'pattern' => $pattern,
                'path' => $basePath,
                'match_count' => count($matches),
                'truncated' => $truncated,
            ]);
        } catch (\Exception $e) {
            return ToolResult::failure("Error searching: {$e->getMessage()}");
        }
    }

    /**
     * Search a single file for matches.
     *
     * @param  array<string>  $matches
     */
    protected function searchFile(string $filePath, string $regex, array &$matches, int $contextLines, int $maxResults, int &$matchCount): void
    {
        if (! is_readable($filePath)) {
            return;
        }

        // Skip binary files
        if ($this->isBinaryFile($filePath)) {
            return;
        }

        // Skip large files
        $maxSize = config('agent.file.max_file_size', 10 * 1024 * 1024);
        if (filesize($filePath) > $maxSize) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return;
        }

        $totalLines = count($lines);
        $basePath = config('agent.search.default_glob_path', base_path());
        $relativePath = str_replace($basePath.'/', '', $filePath);

        for ($i = 0; $i < $totalLines && $matchCount < $maxResults; $i++) {
            if (preg_match($regex, $lines[$i])) {
                $matchCount++;

                // Build output with context
                $output = "{$relativePath}:".($i + 1).':';

                if ($contextLines > 0) {
                    $output .= "\n";
                    $start = max(0, $i - $contextLines);
                    $end = min($totalLines - 1, $i + $contextLines);

                    for ($j = $start; $j <= $end; $j++) {
                        $prefix = ($j === $i) ? '>' : ' ';
                        $output .= sprintf("%s %4d | %s\n", $prefix, $j + 1, $lines[$j]);
                    }
                } else {
                    $output .= $lines[$i];
                }

                $matches[] = trim($output);
            }
        }
    }

    /**
     * Search a directory recursively for matches.
     *
     * @param  array<string>  $matches
     */
    protected function searchDirectory(string $directory, string $regex, ?string $glob, array &$matches, int $contextLines, int $maxResults, int &$matchCount): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $globRegex = $glob ? $this->globToRegex($glob) : null;

        foreach ($iterator as $file) {
            if ($matchCount >= $maxResults) {
                break;
            }

            if ($file->isDir()) {
                continue;
            }

            $filePath = $file->getPathname();

            // Skip excluded directories
            if ($this->isExcludedDirectory($filePath)) {
                continue;
            }

            // Skip files outside allowed paths
            if (! $this->isPathAllowed($filePath)) {
                continue;
            }

            // Apply glob filter if specified
            if ($globRegex !== null) {
                $fileName = $file->getFilename();
                if (! preg_match($globRegex, $fileName)) {
                    continue;
                }
            }

            $this->searchFile($filePath, $regex, $matches, $contextLines, $maxResults, $matchCount);
        }
    }

    /**
     * Check if a file is binary.
     */
    protected function isBinaryFile(string $filePath): bool
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            return true;
        }

        $chunk = fread($handle, 8192);
        fclose($handle);

        if ($chunk === false) {
            return true;
        }

        // Check for null bytes (common in binary files)
        return str_contains($chunk, "\0");
    }

    /**
     * Convert a glob pattern to a regex pattern for filename matching.
     */
    protected function globToRegex(string $pattern): string
    {
        // Handle brace expansion like *.{ts,tsx}
        if (str_contains($pattern, '{')) {
            $pattern = preg_replace_callback('/\{([^}]+)\}/', function ($matches) {
                $options = explode(',', $matches[1]);

                return '('.implode('|', array_map('preg_quote', $options)).')';
            }, $pattern);
        }

        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            switch ($char) {
                case '*':
                    $regex .= '.*';
                    break;
                case '?':
                    $regex .= '.';
                    break;
                case '.':
                case '(':
                case ')':
                case '[':
                case ']':
                case '+':
                case '^':
                case '$':
                case '|':
                case '\\':
                    $regex .= '\\'.$char;
                    break;
                default:
                    $regex .= $char;
            }
        }

        return '/^'.$regex.'$/i';
    }
}
