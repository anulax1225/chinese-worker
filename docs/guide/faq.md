# Frequently Asked Questions

Common questions about self-hosting Chinese Worker.

## General

### What is Chinese Worker?

Chinese Worker is a self-hosted AI agent framework that lets you create intelligent agents powered by large language models. It provides a web interface and API for managing agents, conversations, tools, and system prompts.

### Why would I use this instead of ChatGPT or Claude?

- **Privacy:** Your data stays on your servers
- **Customization:** Create agents with specific behaviors and tools
- **Integration:** Connect to your own APIs and systems
- **Cost control:** Use local models with Ollama (no API costs)
- **No rate limits:** Run as many conversations as your hardware allows

### What AI models can I use?

- **Ollama (local):** Llama 3.x, Qwen 2.5, Mistral, DeepSeek, CodeLlama, and many more
- **Anthropic:** Claude 3 Haiku, Sonnet, Opus
- **OpenAI:** GPT-4, GPT-4 Turbo, GPT-3.5 Turbo

### Do I need a GPU?

No, but it's recommended for local models (Ollama):
- **Without GPU:** Models run on CPU, slower but works
- **With GPU:** Much faster inference, especially for larger models
- **Cloud APIs (Claude/OpenAI):** No local GPU needed

## Installation

### What are the minimum requirements?

**Without local LLM (cloud APIs only):**
- 2 CPU cores
- 4GB RAM
- 20GB disk

**With Ollama (local LLM):**
- 4+ CPU cores
- 16GB+ RAM (depends on model)
- 100GB+ disk
- NVIDIA GPU recommended (not required)

### Can I run this on shared hosting?

No. Chinese Worker requires:
- Shell access
- Background process support (queues)
- Redis or database queues
- Full control over PHP configuration

Use a VPS, dedicated server, or Docker host.

### Can I run this on a Raspberry Pi?

Theoretically possible with cloud APIs (Claude/OpenAI), but not recommended. Local models (Ollama) require more resources than a Pi can provide.

### Does it work on Windows?

For development, use WSL2 (Windows Subsystem for Linux) with Docker.

For production, use a Linux server.

## Configuration

### How do I add my own tools?

1. Go to Tools → Create
2. Define the tool name, type, and configuration
3. Add JSON schema for parameters
4. Attach to agents

Types available:
- **API:** Make HTTP requests to external services
- **Function:** Execute PHP callables
- **Command:** Run shell commands

### How do I customize agent behavior?

1. Create system prompts with Blade templates
2. Use variables like `{{ $agent_name }}`, `{{ $current_date }}`
3. Attach prompts to agents in order
4. Set context variables per agent for custom values

### Can I use multiple AI backends at once?

Yes. Each agent can have its own AI backend. One agent can use Ollama while another uses Claude.

### How do I change the default model?

Edit `.env`:

```env
# For Ollama
OLLAMA_MODEL=qwen2.5:14b

# For Claude
ANTHROPIC_MODEL=claude-3-opus-20240229

# For OpenAI
OPENAI_MODEL=gpt-4-turbo
```

Then clear cache: `php artisan config:cache`

## Usage

### How do conversations work?

1. User sends a message
2. System queues a background job
3. Job builds prompt from templates
4. Job calls AI backend
5. AI responds (possibly with tool calls)
6. System executes tools or waits for client
7. Process repeats until conversation completes

### What are "tool calls"?

Tool calls are requests from the AI to execute a function. For example:
- "Search the web for X" → `web_search` tool
- "Read file Y" → `read` tool
- "Run command Z" → `bash` tool

Some tools execute on the server, others require client confirmation.

### Why does my conversation say "waiting for tool"?

The AI requested a client-side tool (like `bash`) that requires user confirmation. Use the API to submit the tool result:

```bash
POST /api/v1/conversations/{id}/tool-results
{
    "call_id": "...",
    "result": {"success": true, "output": "..."}
}
```

Or refuse it:

```json
{"call_id": "...", "result": {"success": false, "error": "[User refused tool execution]"}}
```

### How do I stream responses in real-time?

Use the SSE endpoint:

```bash
GET /api/v1/conversations/{id}/stream
Accept: text/event-stream
```

This streams events as the AI generates text.

## Security

### Is my data secure?

Your data stays on your servers. Chinese Worker doesn't send data anywhere except:
- The configured AI backend (Ollama is local, Claude/OpenAI are cloud)
- SearXNG for web search (can be self-hosted)

### Can agents access my entire system?

Agents have safety restrictions:
- Blocked dangerous commands (rm -rf, chmod 777, etc.)
- Denied paths (.env, private storage, sessions)
- Command timeout limits
- File size limits

Review and customize in `config/agent.php`.

### Should I expose this to the internet?

Only with proper security:
- HTTPS required
- Strong passwords
- Rate limiting enabled
- Regular updates

Consider VPN or IP whitelist for sensitive deployments.

## Troubleshooting

### Why is Ollama slow?

1. **No GPU:** CPU inference is slower. Consider a GPU or smaller model.
2. **Large model:** Try a smaller variant (7B instead of 70B)
3. **Large context:** Reduce `contextLength` in agent config
4. **Resource contention:** Check if other processes are using CPU/RAM

### Why are my queue jobs failing?

```bash
# Check failed jobs
php artisan queue:failed

# View specific failure
php artisan queue:failed <uuid>
```

Common causes:
- AI backend timeout (increase timeout)
- Memory limit exceeded (increase worker memory)
- Database connection lost (check PostgreSQL)

### Why can't I connect to Ollama from the app?

With Sail, use `http://ollama:11434` (container name), not `localhost`.

Without Sail, ensure Ollama is bound to the correct interface:

```bash
OLLAMA_HOST=0.0.0.0 ollama serve
```

### Where are the logs?

- Application: `storage/logs/laravel.log`
- Horizon: `storage/logs/horizon.log`
- Queue worker: `storage/logs/worker.log`
- Nginx: `/var/log/nginx/`
- PHP-FPM: `/var/log/php/`

## Scaling

### How many concurrent conversations can it handle?

Depends on:
- Queue worker count
- AI backend capacity
- Server resources

Rough estimates:
- 3 workers + Ollama on 8-core server: ~3 concurrent conversations
- 10 workers + cloud API: ~10 concurrent conversations (API rate limits apply)

### Can I run workers on separate servers?

Yes. Install the app on worker servers, configure same Redis connection, and run only Horizon (no web server).

### Can I use a load balancer?

Yes, but:
- Use sticky sessions for WebSocket support
- Ensure shared Redis for sessions/cache/queues
- All servers need same database

## API

### Is there API documentation?

Yes, this guide covers the API in [API Overview](api-overview.md).

If Scribe is configured, auto-generated docs are at `/docs`.

### What's the rate limit?

Default: 60 requests per minute per user.

Customize in `RouteServiceProvider`:

```php
RateLimiter::for('api', function (Request $request) {
    return Limit::perMinute(100)->by($request->user()?->id);
});
```

### Can I use the API from a mobile app?

Yes. Use token-based authentication:

1. Login to get token: `POST /api/v1/auth/login`
2. Include token in requests: `Authorization: Bearer <token>`

## Data

### How is conversation data stored?

- Messages: JSON in `conversations.messages` column
- Files: Local filesystem or S3-compatible storage
- Metadata: Database columns

### Can I export my data?

Currently, there's no built-in export. You can:
- Query the database directly
- Use the API to fetch conversations
- Backup the database

### How do I delete all data?

```bash
# Delete all conversations for a user
php artisan tinker
>>> User::find(ID)->conversations()->delete()

# Or reset entire database
php artisan migrate:fresh
```

## Development

### Can I contribute?

Yes! Check the repository for contribution guidelines.

### How do I add a new AI backend?

1. Create a class implementing `AIBackendInterface`
2. Register in `AIBackendManager`
3. Add configuration in `config/ai.php`

### How do I add new tools?

For built-in tools, modify `ToolSchemaRegistry`.

For user-defined tools, use the Tools API.

## Miscellaneous

### Why is it called "Chinese Worker"?

[This would be filled in by the project maintainers with the origin of the name.]

### Is there a hosted version?

No. Chinese Worker is designed for self-hosting. This gives you full control over your data and configuration.

### What license is this under?

Check the LICENSE file in the repository.

### How do I get support?

- Check this documentation
- Search existing GitHub issues
- Create a new issue with details

### Can I use this commercially?

Check the LICENSE file. Most open-source licenses allow commercial use.
