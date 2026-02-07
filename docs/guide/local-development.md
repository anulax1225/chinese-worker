# Local Development

This guide covers setting up Chinese Worker for local development using Laravel Sail (Docker).

## Prerequisites

- Docker Engine 24.x or higher
- Docker Compose 2.x
- Git

If you don't have Docker, see [Installation](installation.md) for non-Docker setup.

## Quick Setup

```bash
# Clone the repository
git clone <repository-url> chinese-worker
cd chinese-worker

# Copy environment file
cp .env.example .env

# Install PHP dependencies (using Docker)
docker run --rm \
    -u "$(id -u):$(id -g)" \
    -v "$(pwd):/var/www/html" \
    -w /var/www/html \
    laravelsail/php83-composer:latest \
    composer install --ignore-platform-reqs

# Start Sail
./vendor/bin/sail up -d

# Generate application key
./vendor/bin/sail artisan key:generate

# Run migrations
./vendor/bin/sail artisan migrate

# Install frontend dependencies and build
./vendor/bin/sail npm install
./vendor/bin/sail npm run build

# Pull an AI model
./vendor/bin/sail exec ollama ollama pull llama3.1
```

The application will be available at http://localhost.

## Sail Services

The default Sail configuration includes:

| Service | Port | Purpose |
|---------|------|---------|
| **laravel.test** | 80 | Main application |
| **mysql** | 3306 | Database |
| **redis** | 6379 | Cache and queues |
| **ollama** | 11434 | Local LLM backend |
| **searxng** | 8888 | Web search |
| **mailpit** | 8025 | Email testing UI |
| **phpmyadmin** | 8080 | Database admin |
| **rustfs** | 9000/9001 | S3-compatible storage |

### Accessing Services

```bash
# MySQL
./vendor/bin/sail mysql

# Redis
./vendor/bin/sail redis

# Tinker (Laravel REPL)
./vendor/bin/sail tinker

# Ollama
./vendor/bin/sail exec ollama ollama list
```

## Development Commands

### All-in-One Development Server

The recommended way to run development:

```bash
composer dev
```

This starts concurrently:
- PHP development server
- Queue worker
- Log viewer (Pail)
- Vite development server

### Individual Commands

```bash
# Start Sail services
./vendor/bin/sail up -d

# Stop Sail services
./vendor/bin/sail down

# View logs
./vendor/bin/sail logs -f

# Run artisan commands
./vendor/bin/sail artisan <command>

# Run composer commands
./vendor/bin/sail composer <command>

# Run npm commands
./vendor/bin/sail npm <command>
```

## Frontend Development

### Vite Dev Server

For hot module replacement during development:

```bash
./vendor/bin/sail npm run dev
```

Keep this running while developing frontend code. Changes will automatically reload in the browser.

### Building Assets

```bash
# Production build
./vendor/bin/sail npm run build

# SSR build (if using server-side rendering)
./vendor/bin/sail npm run build:ssr
```

### Code Formatting

```bash
# Format frontend code
./vendor/bin/sail npm run format

# Lint frontend code
./vendor/bin/sail npm run lint
```

## Queue Workers

During development, you have two options for processing jobs:

### Option 1: Synchronous (Immediate)

Set in `.env`:
```
QUEUE_CONNECTION=sync
```

Jobs execute immediately in the request cycle. Good for debugging but blocks the request.

### Option 2: Queue Worker

Set in `.env`:
```
QUEUE_CONNECTION=database
```

Run a worker:
```bash
./vendor/bin/sail artisan queue:work --tries=1 --timeout=0
```

Or use `composer dev` which includes a queue worker.

### Horizon (Queue Dashboard)

For monitoring queues in development:

```bash
./vendor/bin/sail artisan horizon
```

Access at http://localhost/horizon

## AI Backend Setup

### Ollama (Default)

Ollama is included in Sail. Pull models to use them:

```bash
# Pull the default model
./vendor/bin/sail exec ollama ollama pull llama3.1

# List available models
./vendor/bin/sail exec ollama ollama list

# Pull additional models
./vendor/bin/sail exec ollama ollama pull qwen2.5
./vendor/bin/sail exec ollama ollama pull mistral
```

### Testing AI Backend

```bash
# Check if Ollama is running
curl http://localhost:11434/api/tags

# Test a simple completion
curl http://localhost:11434/api/generate -d '{
  "model": "llama3.1",
  "prompt": "Hello, world!",
  "stream": false
}'
```

### Using Cloud Backends

To use Anthropic or OpenAI instead of Ollama:

```env
# .env

# Switch to Claude
AI_BACKEND=claude
ANTHROPIC_API_KEY=sk-ant-...

# Or switch to OpenAI
AI_BACKEND=openai
OPENAI_API_KEY=sk-...
```

## Database

### Running Migrations

```bash
./vendor/bin/sail artisan migrate
```

### Seeding Data

```bash
./vendor/bin/sail artisan db:seed
```

### Fresh Start

```bash
# Drop all tables and re-run migrations
./vendor/bin/sail artisan migrate:fresh

# With seeding
./vendor/bin/sail artisan migrate:fresh --seed
```

### Database GUI

phpMyAdmin is available at http://localhost:8080

Credentials:
- Server: `mysql`
- Username: `sail`
- Password: `password`

## Testing

### Running Tests

```bash
# All tests
./vendor/bin/sail artisan test

# With coverage
./vendor/bin/sail artisan test --coverage

# Specific test file
./vendor/bin/sail artisan test tests/Feature/Api/V1/AgentTest.php

# Filter by name
./vendor/bin/sail artisan test --filter=test_can_create_agent
```

### Code Style

```bash
# Check formatting
./vendor/bin/sail composer test:lint

# Fix formatting
./vendor/bin/sail composer lint
```

### Full Test Suite

```bash
./vendor/bin/sail composer test
```

This runs linting checks and all tests.

## Debugging

### Telescope

Laravel Telescope is enabled in local environment. Access at http://localhost/telescope

Features:
- Request inspection
- Exception tracking
- Database queries
- Queue jobs
- Mail preview
- Cache operations
- Scheduled tasks

### Pail (Log Viewer)

Real-time log viewer:

```bash
./vendor/bin/sail artisan pail
```

### Xdebug

Sail includes Xdebug. Configure your IDE with:

- **IDE Key:** `PHPSTORM` or `VSCODE`
- **Port:** 9003
- **Path Mappings:** `/var/www/html` → your project directory

Enable in `.env`:
```env
SAIL_XDEBUG_MODE=develop,debug
```

Restart Sail after changing Xdebug settings.

## Common Development Tasks

### Creating New Components

```bash
# Controller
./vendor/bin/sail artisan make:controller Api/V1/NewController

# Model with migration, factory, and seeder
./vendor/bin/sail artisan make:model NewModel -mfs

# Form Request
./vendor/bin/sail artisan make:request StoreNewRequest

# API Resource
./vendor/bin/sail artisan make:resource NewResource

# Job
./vendor/bin/sail artisan make:job ProcessNewThing

# Test
./vendor/bin/sail artisan make:test --pest Feature/Api/V1/NewTest
```

### Regenerating Routes

After modifying routes, regenerate TypeScript route functions:

```bash
./vendor/bin/sail artisan wayfinder:generate
```

Or Vite does this automatically in development mode.

### Clearing Caches

```bash
# All caches
./vendor/bin/sail artisan optimize:clear

# Specific caches
./vendor/bin/sail artisan cache:clear
./vendor/bin/sail artisan config:clear
./vendor/bin/sail artisan route:clear
./vendor/bin/sail artisan view:clear
```

## Environment Variables

Key development environment variables in `.env`:

```env
APP_ENV=local
APP_DEBUG=true

# Database
DB_CONNECTION=mysql
DB_HOST=mysql
DB_DATABASE=laravel
DB_USERNAME=sail
DB_PASSWORD=password

# Redis
REDIS_HOST=redis

# AI Backend
AI_BACKEND=ollama
OLLAMA_BASE_URL=http://ollama:11434
OLLAMA_MODEL=llama3.1

# Search
SEARXNG_URL=http://searxng:8080

# Queue (use 'sync' for synchronous, 'database' for background)
QUEUE_CONNECTION=database
```

## Troubleshooting

### Sail Won't Start

```bash
# Check Docker is running
docker info

# Rebuild containers
./vendor/bin/sail build --no-cache
./vendor/bin/sail up -d
```

### Port Conflicts

If ports are in use, modify `docker-compose.yml` or use `.env` variables:

```env
APP_PORT=8000
FORWARD_DB_PORT=33060
FORWARD_REDIS_PORT=63790
```

### Permission Issues

```bash
# Fix storage permissions
./vendor/bin/sail artisan storage:link
chmod -R 775 storage bootstrap/cache
```

### Ollama Not Responding

```bash
# Check Ollama container
./vendor/bin/sail exec ollama ollama list

# Restart Ollama
./vendor/bin/sail restart ollama

# Check logs
./vendor/bin/sail logs ollama
```

## Next Steps

- [Configuration](configuration.md) - Detailed configuration options
- [AI Backends](ai-backends.md) - Configure AI providers
- [Testing](../context/README.md) - Testing guidelines
