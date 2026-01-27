<?php

namespace App\Services\BuiltinTools;

use App\DTOs\ToolResult;
use Illuminate\Support\Facades\Process;

class BashTool extends AbstractBuiltinTool
{
    public function getName(): string
    {
        return 'bash';
    }

    public function getDescription(): string
    {
        return 'Execute a shell command. Use for git, npm, build tools, and other terminal operations.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'command' => [
                    'type' => 'string',
                    'description' => 'The shell command to execute',
                ],
                'timeout' => [
                    'type' => 'integer',
                    'description' => 'Maximum execution time in seconds. Default: 120',
                ],
                'cwd' => [
                    'type' => 'string',
                    'description' => 'Working directory for the command. Defaults to project root.',
                ],
            ],
            'required' => ['command'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $command = $arguments['command'];
        $timeout = (int) ($arguments['timeout'] ?? config('agent.command.timeout', 120));
        $cwd = isset($arguments['cwd'])
            ? $this->normalizePath($arguments['cwd'])
            : base_path();

        // Validate working directory
        if (! $this->isPathAllowed($cwd)) {
            return ToolResult::failure("Access denied: {$cwd} is not within allowed paths");
        }

        if (! is_dir($cwd)) {
            return ToolResult::failure("Working directory not found: {$cwd}");
        }

        // Check for dangerous commands
        $dangerCheck = $this->checkDangerousCommand($command);
        if ($dangerCheck !== null) {
            return ToolResult::failure($dangerCheck);
        }

        // Check for allowed commands if specified
        $allowedCommands = config('agent.command.allowed_commands', []);
        if (! empty($allowedCommands)) {
            $baseCommand = $this->extractBaseCommand($command);
            if (! in_array($baseCommand, $allowedCommands)) {
                return ToolResult::failure("Command not allowed: {$baseCommand}");
            }
        }

        try {
            $result = Process::timeout($timeout)
                ->path($cwd)
                ->run($command);

            $stdout = $result->output();
            $stderr = $result->errorOutput();
            $exitCode = $result->exitCode();

            // Truncate output if too large
            $maxOutputSize = 100000; // 100KB
            $truncatedStdout = strlen($stdout) > $maxOutputSize;
            $truncatedStderr = strlen($stderr) > $maxOutputSize;

            if ($truncatedStdout) {
                $stdout = substr($stdout, 0, $maxOutputSize).'... (output truncated)';
            }
            if ($truncatedStderr) {
                $stderr = substr($stderr, 0, $maxOutputSize).'... (error output truncated)';
            }

            // Build output
            $output = '';
            if (! empty($stdout)) {
                $output .= $stdout;
            }
            if (! empty($stderr)) {
                if (! empty($output)) {
                    $output .= "\n\n";
                }
                $output .= "STDERR:\n".$stderr;
            }

            if (empty($output)) {
                $output = '(no output)';
            }

            // Add exit code if non-zero
            if ($exitCode !== 0) {
                $output .= "\n\nExit code: {$exitCode}";
            }

            $success = $exitCode === 0;

            if ($success) {
                return ToolResult::success($output, [
                    'exit_code' => $exitCode,
                    'command' => $command,
                    'cwd' => $cwd,
                ]);
            } else {
                return ToolResult::failure($output, [
                    'exit_code' => $exitCode,
                    'command' => $command,
                    'cwd' => $cwd,
                ]);
            }
        } catch (\Illuminate\Process\Exceptions\ProcessTimedOutException $e) {
            return ToolResult::failure("Command timed out after {$timeout} seconds", [
                'command' => $command,
                'timeout' => $timeout,
            ]);
        } catch (\Exception $e) {
            return ToolResult::failure("Error executing command: {$e->getMessage()}", [
                'command' => $command,
            ]);
        }
    }

    /**
     * Check if a command is dangerous.
     *
     * @return string|null Error message if dangerous, null if safe
     */
    protected function checkDangerousCommand(string $command): ?string
    {
        $dangerousPatterns = config('agent.command.dangerous_patterns', []);

        foreach ($dangerousPatterns as $pattern) {
            if (str_contains(strtolower($command), strtolower($pattern))) {
                return "Dangerous command blocked: {$pattern}";
            }
        }

        // Additional safety checks
        $additionalDangerousPatterns = [
            '/>\s*\/dev\/sd/',     // Write to disk devices
            '/mkfs\s/',            // Format filesystems
            '/dd\s+if=/',          // Low-level disk operations
            '/:(){:|:&};:/',       // Fork bomb
            '/wget.*\|\s*sh/',     // Download and execute
            '/curl.*\|\s*sh/',     // Download and execute
            '/eval\s*\$\(/',       // Eval with command substitution
        ];

        foreach ($additionalDangerousPatterns as $regex) {
            if (preg_match($regex, $command)) {
                return 'Potentially dangerous command pattern detected';
            }
        }

        return null;
    }

    /**
     * Extract the base command from a full command string.
     */
    protected function extractBaseCommand(string $command): string
    {
        // Handle pipes, redirects, etc.
        $parts = preg_split('/[\s|;&]/', $command, 2);

        return $parts[0] ?? $command;
    }

    public function validate(array $arguments): array
    {
        $errors = parent::validate($arguments);

        if (isset($arguments['command']) && trim($arguments['command']) === '') {
            $errors[] = 'Command cannot be empty';
        }

        if (isset($arguments['timeout']) && $arguments['timeout'] < 1) {
            $errors[] = 'Timeout must be at least 1 second';
        }

        return $errors;
    }
}
