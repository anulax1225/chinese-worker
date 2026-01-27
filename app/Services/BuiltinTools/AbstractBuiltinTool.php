<?php

namespace App\Services\BuiltinTools;

use App\Contracts\BuiltinToolInterface;

abstract class AbstractBuiltinTool implements BuiltinToolInterface
{
    /**
     * Check if a path is within allowed directories.
     */
    protected function isPathAllowed(string $path): bool
    {
        $realPath = realpath($path);
        if ($realPath === false) {
            // Path doesn't exist, check up the parent chain until we find an existing directory
            $realPath = $this->findExistingParent($path);
            if ($realPath === null) {
                return false;
            }
        }

        $allowedPaths = config('agent.allowed_paths', [base_path()]);
        $deniedPaths = config('agent.denied_paths', []);

        // Check if path is in denied paths
        foreach ($deniedPaths as $denied) {
            $deniedReal = realpath($denied);
            if ($deniedReal !== false && str_starts_with($realPath, $deniedReal)) {
                return false;
            }
        }

        // Check if path is in allowed paths
        foreach ($allowedPaths as $allowed) {
            $allowedReal = realpath($allowed);
            if ($allowedReal !== false && str_starts_with($realPath, $allowedReal)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the first existing parent directory of a path.
     */
    protected function findExistingParent(string $path): ?string
    {
        $current = dirname($path);
        $maxDepth = 20; // Prevent infinite loops
        $depth = 0;

        while ($depth < $maxDepth) {
            $realPath = realpath($current);
            if ($realPath !== false) {
                return $realPath;
            }

            $parent = dirname($current);
            if ($parent === $current) {
                // Reached filesystem root
                break;
            }

            $current = $parent;
            $depth++;
        }

        return null;
    }

    /**
     * Normalize a path to absolute form.
     */
    protected function normalizePath(string $path): string
    {
        if (! str_starts_with($path, '/')) {
            $path = base_path($path);
        }

        return $path;
    }

    /**
     * Check if a directory should be excluded from search.
     */
    protected function isExcludedDirectory(string $path): bool
    {
        $excludedDirs = config('agent.search.excluded_directories', []);

        foreach ($excludedDirs as $excluded) {
            if (str_contains($path, "/{$excluded}/") || str_ends_with($path, "/{$excluded}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Default validation implementation.
     */
    public function validate(array $arguments): array
    {
        $errors = [];
        $schema = $this->getParameterSchema();
        $required = $schema['required'] ?? [];

        foreach ($required as $param) {
            if (! isset($arguments[$param]) || $arguments[$param] === '') {
                $errors[] = "Missing required parameter: {$param}";
            }
        }

        return $errors;
    }
}
