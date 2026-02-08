<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Agentic Loop Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the multi-turn agentic execution loop.
    |
    */

    'max_turns' => env('AGENT_MAX_TURNS', 25),

    'on_tool_error' => env('AGENT_ON_TOOL_ERROR', 'stop'), // 'stop', 'continue', 'retry'

    /*
    |--------------------------------------------------------------------------
    | Path Security
    |--------------------------------------------------------------------------
    |
    | Control which paths the agent can read from and write to.
    |
    */

    'allowed_paths' => [
        base_path(),
    ],

    'denied_paths' => [
        base_path('.env'),
        base_path('.env.local'),
        base_path('.env.production'),
        base_path('storage/app/private'),
        base_path('storage/framework/sessions'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Execution
    |--------------------------------------------------------------------------
    |
    | Settings for the bash tool command execution.
    |
    */

    'command' => [
        'timeout' => env('AGENT_COMMAND_TIMEOUT', 120),

        'allowed_commands' => [
            // Empty means all commands are allowed (except dangerous ones)
        ],

        'dangerous_patterns' => [
            'rm -rf /',
            'rm -rf ~',
            'rm -rf *',
            'sudo rm',
            'chmod 777',
            'chmod -R 777',
            'mkfs',
            'dd if=',
            ':(){:|:&};:',
            '> /dev/sda',
            'wget * | sh',
            'curl * | sh',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | File Operations
    |--------------------------------------------------------------------------
    |
    | Settings for read/write/edit tools.
    |
    */

    'file' => [
        'max_read_lines' => 2000,
        'max_file_size' => 10 * 1024 * 1024, // 10MB
        'line_number_format' => '%6d | %s',
    ],

    /*
    |--------------------------------------------------------------------------
    | Search Operations
    |--------------------------------------------------------------------------
    |
    | Settings for glob and grep tools.
    |
    */

    'search' => [
        'max_results' => 1000,
        'default_glob_path' => base_path(),
        'excluded_directories' => [
            'node_modules',
            'vendor',
            '.git',
            'storage/framework',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | API Tool Security
    |--------------------------------------------------------------------------
    |
    | Security settings for API-type tools that make HTTP requests.
    |
    */

    'api_tools' => [
        // Block requests to private/internal IP addresses (SSRF protection)
        // Disabled by default for local development, enable in production
        'block_private_ips' => env('TOOLS_BLOCK_PRIVATE_IPS', false),

        // Additional blocked hosts (domains or IPs)
        'blocked_hosts' => [
            // 'internal.example.com',
        ],
    ],

];
