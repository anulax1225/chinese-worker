# Configuration

This document provides a comprehensive reference for all configuration options in Chinese Worker.

## Configuration Files

Configuration files are located in the `config/` directory. Most values can be overridden via environment variables in `.env`.

### config/ai.php

AI backend configuration.

```php
return [
    // Default AI backend driver
    'default' => env('AI_BACKEND', 'ollama'),

    // Backend configurations
    'backends' => [
        'ollama' => [
            'base_url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
            'model' => env('OLLAMA_MODEL', 'llama3.1'),
            'timeout' => env('OLLAMA_TIMEOUT', 120),
            'options' => [
                'temperature' => 0.7,
                'num_ctx' => 4096,
            ],
        ],

        'claude' => [
            'api_key' => env('ANTHROPIC_API_KEY'),
            'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5-20250929'),
            'max_tokens' => 4096,
            'timeout' => 120,
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4'),
            'max_tokens' => 4096,
            'timeout' => 120,
        ],
    ],
];
```

**Environment Variables:**

| Variable | Default | Description |
|----------|---------|-------------|
| `AI_BACKEND` | `ollama` | Default AI backend (`ollama`, `claude`, `openai`) |
| `OLLAMA_BASE_URL` | `http://localhost:11434` | Ollama server URL |
| `OLLAMA_MODEL` | `llama3.1` | Default Ollama model |
| `OLLAMA_TIMEOUT` | `120` | Request timeout in seconds |
| `ANTHROPIC_API_KEY` | - | Anthropic API key |
| `ANTHROPIC_MODEL` | `claude-sonnet-4-5-20250929` | Default Claude model |
| `OPENAI_API_KEY` | - | OpenAI API key |
| `OPENAI_MODEL` | `gpt-4` | Default OpenAI model |

### config/agent.php

Agent behavior and safety configuration.

```php
return [
    // Maximum conversation turns before stopping
    'max_turns' => env('AGENT_MAX_TURNS', 25),

    // Behavior when tool execution fails: 'stop', 'continue', 'retry'
    'tool_error_behavior' => 'stop',

    // Paths agents can access
    'allowed_paths' => [
        base_path(),
    ],

    // Paths agents cannot access
    'denied_paths' => [
        '.env',
        '.env.local',
        '.env.production',
        'storage/app/private',
        'storage/framework/sessions',
    ],

    // Command execution timeout
    'command_timeout' => 120,

    // Dangerous command patterns (blocked)
    'dangerous_patterns' => [
        'rm -rf',
        'chmod 777',
        'mkfs',
        // ... more patterns
    ],

    // File operation limits
    'file' => [
        'max_read_lines' => 2000,
        'max_file_size' => 10 * 1024 * 1024, // 10MB
    ],

    // Search settings
    'search' => [
        'max_results' => 1000,
        'exclude_dirs' => ['node_modules', 'vendor', '.git', 'storage/framework'],
    ],
];
```

**Environment Variables:**

| Variable | Default | Description |
|----------|---------|-------------|
| `AGENT_MAX_TURNS` | `25` | Maximum turns per conversation |

### config/search.php

Web search configuration.

```php
return [
    // Search driver
    'driver' => env('SEARCH_DRIVER', 'searxng'),

    // SearXNG configuration
    'searxng' => [
        'url' => env('SEARXNG_URL', 'http://localhost:8080'),
        'timeout' => env('SEARXNG_TIMEOUT', 10),
        'engines' => explode(',', env('SEARXNG_ENGINES', 'google,bing,duckduckgo')),
        'safe_search' => env('SEARXNG_SAFE_SEARCH', 1),
    ],

    // Search result caching
    'cache' => [
        'enabled' => env('SEARCH_CACHE_ENABLED', true),
        'ttl' => env('SEARCH_CACHE_TTL', 3600), // 1 hour
        'store' => env('SEARCH_CACHE_STORE', 'redis'),
        'prefix' => 'search:',
    ],

    // Default search options
    'defaults' => [
        'max_results' => 5,
        'language' => 'auto',
        'time_range' => 'all',
    ],

    // Retry configuration
    'retry' => [
        'times' => 2,
        'sleep_ms' => 500,
    ],
];
```

**Environment Variables:**

| Variable | Default | Description |
|----------|---------|-------------|
| `SEARCH_DRIVER` | `searxng` | Search backend driver |
| `SEARXNG_URL` | `http://localhost:8080` | SearXNG instance URL |
| `SEARXNG_TIMEOUT` | `10` | Search timeout in seconds |
| `SEARXNG_ENGINES` | `google,bing,duckduckgo` | Comma-separated engine list |
| `SEARXNG_SAFE_SEARCH` | `1` | Safe search level (0=off, 1=moderate, 2=strict) |
| `SEARCH_CACHE_ENABLED` | `true` | Enable result caching |
| `SEARCH_CACHE_TTL` | `3600` | Cache TTL in seconds |
| `SEARCH_CACHE_STORE` | `redis` | Cache store driver |

### config/webfetch.php

Web content fetching configuration.

```php
return [
    // Request timeout
    'timeout' => env('WEBFETCH_TIMEOUT', 15),

    // Maximum response size
    'max_size' => env('WEBFETCH_MAX_SIZE', 5 * 1024 * 1024), // 5MB

    // User agent string
    'user_agent' => env('WEBFETCH_USER_AGENT', 'ChineseWorker/1.0 (Web Fetch Bot)'),

    // Allowed content types
    'allowed_content_types' => [
        'text/html',
        'text/plain',
        'application/json',
        'application/xml',
        'text/xml',
        'application/xhtml+xml',
    ],

    // Content caching
    'cache' => [
        'enabled' => env('WEBFETCH_CACHE_ENABLED', true),
        'ttl' => env('WEBFETCH_CACHE_TTL', 1800), // 30 minutes
        'store' => env('WEBFETCH_CACHE_STORE', 'redis'),
        'prefix' => 'webfetch:',
    ],

    // Retry configuration
    'retry' => [
        'times' => 2,
        'sleep_ms' => 500,
    ],

    // Content extraction
    'extraction' => [
        'max_text_length' => env('WEBFETCH_MAX_TEXT', 50000),
        'remove_scripts' => true,
        'remove_styles' => true,
        'remove_navigation' => true,
    ],
];
```

**Environment Variables:**

| Variable | Default | Description |
|----------|---------|-------------|
| `WEBFETCH_TIMEOUT` | `15` | Request timeout in seconds |
| `WEBFETCH_MAX_SIZE` | `5242880` | Max response size in bytes |
| `WEBFETCH_USER_AGENT` | `ChineseWorker/1.0` | HTTP User-Agent |
| `WEBFETCH_CACHE_ENABLED` | `true` | Enable content caching |
| `WEBFETCH_CACHE_TTL` | `1800` | Cache TTL in seconds |
| `WEBFETCH_CACHE_STORE` | `redis` | Cache store driver |
| `WEBFETCH_MAX_TEXT` | `50000` | Max extracted text length |

### config/horizon.php

Queue worker configuration.

```php
return [
    // Dashboard path
    'path' => '/horizon',

    // Redis connection
    'use' => 'default',

    // Key prefix
    'prefix' => env('HORIZON_PREFIX', 'chinese_worker_horizon:'),

    // Worker pools
    'environments' => [
        'production' => [
            'ai-workers' => [
                'connection' => 'redis',
                'queue' => ['high', 'default'],
                'balance' => 'auto',
                'processes' => 10,
                'timeout' => 330,    // 5.5 minutes (for long AI responses)
                'memory' => 256,
                'tries' => 1,
            ],
            'low-priority' => [
                'connection' => 'redis',
                'queue' => ['low'],
                'balance' => 'auto',
                'processes' => 2,
                'timeout' => 120,
                'memory' => 128,
                'tries' => 3,
            ],
        ],

        'local' => [
            'ai-workers' => [
                'connection' => 'redis',
                'queue' => ['high', 'default'],
                'balance' => 'auto',
                'processes' => 3,
                'timeout' => 330,
                'memory' => 256,
                'tries' => 1,
            ],
            'low-priority' => [
                'connection' => 'redis',
                'queue' => ['low'],
                'balance' => 'auto',
                'processes' => 1,
                'timeout' => 120,
                'memory' => 128,
                'tries' => 3,
            ],
        ],
    ],
];
```

**Key Settings:**

| Setting | Value | Description |
|---------|-------|-------------|
| `timeout` | `330` | Worker timeout (must exceed longest job) |
| `processes` | `3-10` | Number of worker processes |
| `memory` | `256` | Memory limit in MB |
| `tries` | `1` | Retry attempts (1 for AI jobs to avoid duplicates) |

## Core Environment Variables

### Application

```env
APP_NAME="Chinese Worker"
APP_ENV=production          # local, production
APP_DEBUG=false             # true for development
APP_URL=https://example.com
APP_KEY=                    # Auto-generated
```

### Database

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=chinese_worker
DB_USERNAME=app
DB_PASSWORD=secret
```

### Redis

```env
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379
```

### Queue and Cache

```env
QUEUE_CONNECTION=database    # sync, database, redis
CACHE_STORE=redis           # file, redis, database
SESSION_DRIVER=database     # file, database, redis
```

### Broadcasting (Reverb)

```env
BROADCAST_CONNECTION=reverb
REVERB_APP_ID=123456
REVERB_APP_KEY=your-key
REVERB_APP_SECRET=your-secret
REVERB_HOST=localhost
REVERB_PORT=8080
REVERB_SCHEME=http          # http or https

# Frontend (Vite)
VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
VITE_REVERB_HOST="${REVERB_HOST}"
VITE_REVERB_PORT="${REVERB_PORT}"
VITE_REVERB_SCHEME="${REVERB_SCHEME}"
```

### Mail

```env
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=user
MAIL_PASSWORD=secret
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

### Storage (S3-compatible)

```env
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=your-bucket
AWS_ENDPOINT=                # For MinIO/RustFS
AWS_USE_PATH_STYLE_ENDPOINT=true
```

## Development vs Production

### Development (.env)

```env
APP_ENV=local
APP_DEBUG=true
QUEUE_CONNECTION=sync        # Immediate job execution
CACHE_STORE=database
LOG_LEVEL=debug
```

### Production (.env)

```env
APP_ENV=production
APP_DEBUG=false
QUEUE_CONNECTION=database
CACHE_STORE=redis
LOG_LEVEL=warning
```

## Configuration Caching

In production, cache configuration for performance:

```bash
# Cache all configuration
php artisan config:cache

# Clear cached configuration
php artisan config:clear

# View effective configuration
php artisan config:show ai
php artisan config:show search
```

**Important:** After caching, `.env` changes require re-caching:

```bash
php artisan config:cache
```

## Runtime Configuration

Some settings can be overridden per-agent:

### Agent Model Config

Stored as JSON in `agents.model_config`:

```json
{
    "model": "llama3.2",
    "temperature": 0.5,
    "maxTokens": 2048,
    "contextLength": 8192,
    "timeout": 60,
    "topP": 0.9,
    "topK": 40
}
```

These override global backend defaults for that specific agent.

### System Prompt Variables

System prompts support Blade templating with context variables:

**Available automatically:**
- `{{ $agent_name }}` - Agent's name
- `{{ $agent_description }}` - Agent's description
- `{{ $current_date }}` - Current date
- `{{ $current_time }}` - Current time
- `{{ $current_datetime }}` - Current datetime

**Agent context variables:**
Set via `agents.context_variables` JSON:
```json
{
    "company_name": "Acme Corp",
    "support_email": "support@acme.com"
}
```

Use in prompts as `{{ $company_name }}`.

## Next Steps

- [AI Backends](ai-backends.md) - Detailed AI backend configuration
- [Search & WebFetch](search-and-webfetch.md) - Web integration setup
- [Queues & Jobs](queues-and-jobs.md) - Queue worker configuration
