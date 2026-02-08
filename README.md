# Chinese Worker

A self-hosted AI agent framework built with Laravel 12. Create intelligent agents with pluggable AI backends, tool execution, web search, and multi-turn conversations.

## Features

- **Multi-Backend AI Support** - Switch between Ollama (local), Anthropic Claude, or OpenAI
- **Agent Management** - Create agents with custom system prompts, tools, and model configurations
- **Multi-Turn Conversations** - Stateful conversations with message history and context
- **Tool Execution** - Built-in tools (bash, file operations, search) plus custom tool definitions
- **Web Search & Fetch** - Integrated SearXNG search and web content extraction
- **Real-Time Streaming** - Server-Sent Events for live response streaming
- **System Prompt Templating** - Blade-based prompts with variable substitution
- **Queue Processing** - Background job processing with Horizon monitoring
- **Modern Frontend** - Vue 3 + Inertia.js SPA with Tailwind CSS

## Quick Start

### With Docker (Recommended)

```bash
# Clone and enter the project
git clone <repository-url> chinese-worker
cd chinese-worker

# Copy environment file
cp .env.example .env

# Start services with Sail
./vendor/bin/sail up -d

# Install dependencies and setup
./vendor/bin/sail composer setup

# Pull an AI model (required for Ollama)
./vendor/bin/sail exec ollama ollama pull llama3.1

# Access the application
open http://localhost
```

### Without Docker

See [Installation Guide](docs/guide/installation.md) for production deployment without Docker.

## Requirements

- PHP 8.2+
- MySQL 8.0+ or PostgreSQL
- Redis
- Node.js 20+
- Ollama, Anthropic API, or OpenAI API (at least one AI backend)
- SearXNG (optional, for web search)

See [Requirements](docs/guide/requirements.md) for full details.

## Documentation

All documentation is in the [`docs/guide/`](docs/guide/) directory:

### Getting Started
- [Introduction](docs/guide/introduction.md) - Overview and key concepts
- [Architecture](docs/guide/architecture.md) - System components and data flow
- [Requirements](docs/guide/requirements.md) - Full system requirements

### Setup & Installation
- [Local Development](docs/guide/local-development.md) - Development with Laravel Sail
- [Installation](docs/guide/installation.md) - Production installation guide
- [Configuration](docs/guide/configuration.md) - Configuration reference

### Features
- [AI Backends](docs/guide/ai-backends.md) - Configuring Ollama, Claude, and OpenAI
- [Search & Web Fetch](docs/guide/search-and-webfetch.md) - Web search and content extraction
- [Queues & Jobs](docs/guide/queues-and-jobs.md) - Background processing with Horizon

### Operations
- [Production](docs/guide/production.md) - Production deployment and optimization
- [Updating](docs/guide/updating.md) - Safe update procedures
- [Security](docs/guide/security.md) - Security best practices

### Reference
- [API Overview](docs/guide/api-overview.md) - REST API documentation
- [Troubleshooting](docs/guide/troubleshooting.md) - Common issues and solutions
- [FAQ](docs/guide/faq.md) - Frequently asked questions

## Development

```bash
# Start all services (PHP, queue, logs, Vite)
composer dev

# Run tests
composer test

# Format code
composer lint
```

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                         Web UI (Vue 3)                          │
│                      Inertia.js + Tailwind                      │
└─────────────────────────────────────────────────────────────────┘
                                │
┌─────────────────────────────────────────────────────────────────┐
│                        Laravel 12 API                           │
│              Sanctum Auth │ Form Requests │ Resources           │
└─────────────────────────────────────────────────────────────────┘
                                │
        ┌───────────────────────┼───────────────────────┐
        ▼                       ▼                       ▼
┌───────────────┐     ┌─────────────────┐     ┌─────────────────┐
│    Agents     │     │  Conversations  │     │      Tools      │
│ System Prompts│     │    Messages     │     │   Execution     │
│  Model Config │     │  Tool Requests  │     │                 │
└───────────────┘     └─────────────────┘     └─────────────────┘
        │                       │                       │
        └───────────────────────┼───────────────────────┘
                                │
┌─────────────────────────────────────────────────────────────────┐
│                       AIBackendManager                          │
│           Ollama │ Anthropic Claude │ OpenAI                    │
└─────────────────────────────────────────────────────────────────┘
                                │
┌─────────────────────────────────────────────────────────────────┐
│                     Background Jobs                             │
│     ProcessConversationTurn │ PullModelJob │ Cleanup            │
└─────────────────────────────────────────────────────────────────┘
```

## Stack

| Component | Technology |
|-----------|------------|
| Backend | Laravel 12, PHP 8.2+ |
| Frontend | Vue 3, Inertia.js v2, TypeScript |
| Styling | Tailwind CSS v4 |
| Database | MySQL 8.0+ / PostgreSQL |
| Cache/Queue | Redis |
| AI Backends | Ollama, Anthropic, OpenAI |
| Search | SearXNG |
| Queue Monitor | Laravel Horizon |
| WebSockets | Laravel Reverb |
| Auth | Laravel Sanctum + Fortify |

## License

[MIT License](LICENSE)
