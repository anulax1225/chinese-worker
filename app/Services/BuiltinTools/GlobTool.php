<?php

namespace App\Services\BuiltinTools;

use App\DTOs\ToolResult;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class GlobTool extends AbstractBuiltinTool
{
    public function getName(): string
    {
        return 'glob';
    }

    public function getDescription(): string
    {
        return 'Find files matching a glob pattern. Supports patterns like "**/*.php" or "src/**/*.ts".';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'pattern' => [
                    'type' => 'string',
                    'description' => 'The glob pattern to match files (e.g., "**/*.php", "src/**/*.ts")',
                ],
                'path' => [
                    'type' => 'string',
                    'description' => 'The directory to search in. Defaults to the project root.',
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

        // Validate path
        if (! $this->isPathAllowed($basePath)) {
            return ToolResult::failure("Access denied: {$basePath} is not within allowed paths");
        }

        // Check if path exists
        if (! is_dir($basePath)) {
            return ToolResult::failure("Directory not found: {$basePath}");
        }

        try {
            $matches = $this->findFiles($basePath, $pattern);
            $maxResults = config('agent.search.max_results', 1000);

            $truncated = false;
            if (count($matches) > $maxResults) {
                $matches = array_slice($matches, 0, $maxResults);
                $truncated = true;
            }

            // Sort by modification time (newest first)
            usort($matches, function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });

            // Format output
            $output = [];
            foreach ($matches as $file) {
                // Show relative path from base
                $relativePath = str_replace($basePath.'/', '', $file);
                $output[] = $relativePath;
            }

            if (empty($output)) {
                return ToolResult::success("No files found matching pattern: {$pattern}", [
                    'pattern' => $pattern,
                    'path' => $basePath,
                    'count' => 0,
                ]);
            }

            $result = implode("\n", $output);
            if ($truncated) {
                $result .= "\n... (truncated at {$maxResults} results)";
            }

            return ToolResult::success($result, [
                'pattern' => $pattern,
                'path' => $basePath,
                'count' => count($output),
                'truncated' => $truncated,
            ]);
        } catch (\Exception $e) {
            return ToolResult::failure("Error searching files: {$e->getMessage()}");
        }
    }

    /**
     * Find files matching a glob pattern.
     *
     * @return array<string>
     */
    protected function findFiles(string $basePath, string $pattern): array
    {
        $matches = [];

        // Convert glob pattern to regex
        $regex = $this->globToRegex($pattern);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            // Skip directories
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

            // Get relative path for matching
            $relativePath = str_replace($basePath.'/', '', $filePath);

            // Match against pattern
            if (preg_match($regex, $relativePath)) {
                $matches[] = $filePath;
            }
        }

        return $matches;
    }

    /**
     * Convert a glob pattern to a regex pattern.
     */
    protected function globToRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);

        for ($i = 0; $i < $length; $i++) {
            $char = $pattern[$i];

            switch ($char) {
                case '*':
                    // Check for **
                    if ($i + 1 < $length && $pattern[$i + 1] === '*') {
                        // ** matches any path including subdirectories
                        $regex .= '.*';
                        $i++; // Skip next *

                        // If followed by /, skip it too
                        if ($i + 1 < $length && $pattern[$i + 1] === '/') {
                            $i++;
                        }
                    } else {
                        // * matches anything except /
                        $regex .= '[^/]*';
                    }
                    break;

                case '?':
                    // ? matches any single character except /
                    $regex .= '[^/]';
                    break;

                case '.':
                case '(':
                case ')':
                case '{':
                case '}':
                case '[':
                case ']':
                case '+':
                case '^':
                case '$':
                case '|':
                case '\\':
                    // Escape regex special characters
                    $regex .= '\\'.$char;
                    break;

                default:
                    $regex .= $char;
            }
        }

        return '#^'.$regex.'$#i';
    }
}
