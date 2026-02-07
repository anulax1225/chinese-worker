# API Overview

Chinese Worker provides a RESTful API for all functionality. This document covers the API structure, authentication, and available endpoints.

## Base URL

All API endpoints are prefixed with `/api/v1`.

```
https://your-domain.com/api/v1
```

## Authentication

### Token-Based Authentication

The API uses Laravel Sanctum for token-based authentication.

#### Obtain a Token

```bash
POST /api/v1/auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "your-password"
}
```

Response:

```json
{
    "user": {
        "id": 1,
        "name": "User Name",
        "email": "user@example.com"
    },
    "token": "1|abc123..."
}
```

#### Using the Token

Include the token in the `Authorization` header:

```bash
GET /api/v1/agents
Authorization: Bearer 1|abc123...
```

#### Logout

```bash
POST /api/v1/auth/logout
Authorization: Bearer 1|abc123...
```

### SPA Authentication

For browser-based SPAs, Sanctum uses cookie-based authentication:

1. Include `X-XSRF-TOKEN` header from cookie
2. Set `SANCTUM_STATEFUL_DOMAINS` in `.env`

## Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/auth/register` | Register new user |
| `POST` | `/auth/login` | Login and get token |
| `POST` | `/auth/logout` | Logout (revoke token) |
| `GET` | `/auth/user` | Get authenticated user |

### Agents

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/agents` | List user's agents |
| `POST` | `/agents` | Create new agent |
| `GET` | `/agents/{id}` | Get agent details |
| `PUT` | `/agents/{id}` | Update agent |
| `DELETE` | `/agents/{id}` | Delete agent |
| `POST` | `/agents/{id}/tools` | Attach tools to agent |
| `DELETE` | `/agents/{id}/tools/{toolId}` | Detach tool from agent |

#### Create Agent Example

```bash
POST /api/v1/agents
Content-Type: application/json

{
    "name": "Research Assistant",
    "description": "Helps with research tasks",
    "ai_backend": "ollama",
    "model_config": {
        "model": "llama3.1",
        "temperature": 0.7,
        "maxTokens": 4096
    },
    "tool_ids": [1, 2, 3],
    "system_prompt_ids": [1, 2]
}
```

### Conversations

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/agents/{agentId}/conversations` | Start conversation with agent |
| `GET` | `/conversations` | List user's conversations |
| `GET` | `/conversations/{id}` | Get conversation details |
| `POST` | `/conversations/{id}/messages` | Send message |
| `GET` | `/conversations/{id}/status` | Poll conversation status |
| `GET` | `/conversations/{id}/stream` | SSE stream for real-time updates |
| `POST` | `/conversations/{id}/tool-results` | Submit tool execution result |
| `DELETE` | `/conversations/{id}` | Delete conversation |

#### Start Conversation

```bash
POST /api/v1/agents/1/conversations
Content-Type: application/json

{
    "metadata": {
        "client_type": "web"
    }
}
```

#### Send Message

```bash
POST /api/v1/conversations/1/messages
Content-Type: application/json

{
    "message": "Hello, can you help me with a task?",
    "images": []
}
```

#### Poll Status

```bash
GET /api/v1/conversations/1/status
```

Response:

```json
{
    "status": "processing",
    "pending_tool": null,
    "new_messages": null,
    "error": null
}
```

Or when waiting for a tool:

```json
{
    "status": "waiting_for_tool",
    "pending_tool": {
        "id": "call_123",
        "name": "bash",
        "arguments": {
            "command": "ls -la"
        }
    },
    "new_messages": null,
    "error": null
}
```

#### SSE Stream

```bash
GET /api/v1/conversations/1/stream
Accept: text/event-stream
```

Events:

```
event: text_chunk
data: {"chunk": "Hello", "type": "content"}

event: tool_request
data: {"call_id": "call_123", "name": "bash", "arguments": {"command": "ls"}}

event: tool_completed
data: {"call_id": "call_123", "success": true, "output": "..."}

event: completed
data: {}
```

#### Submit Tool Result

```bash
POST /api/v1/conversations/1/tool-results
Content-Type: application/json

{
    "call_id": "call_123",
    "result": {
        "success": true,
        "output": "file1.txt\nfile2.txt"
    }
}
```

Or refuse tool execution:

```json
{
    "call_id": "call_123",
    "result": {
        "success": false,
        "error": "[User refused tool execution]"
    }
}
```

### Tools

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/tools` | List tools (includes builtin) |
| `POST` | `/tools` | Create custom tool |
| `GET` | `/tools/{id}` | Get tool details |
| `PUT` | `/tools/{id}` | Update tool |
| `DELETE` | `/tools/{id}` | Delete tool |

#### Create Tool Example

```bash
POST /api/v1/tools
Content-Type: application/json

{
    "name": "weather_api",
    "type": "api",
    "config": {
        "description": "Get current weather for a location",
        "endpoint": "https://api.weather.com/current",
        "method": "GET",
        "parameters": {
            "type": "object",
            "properties": {
                "location": {
                    "type": "string",
                    "description": "City name"
                }
            },
            "required": ["location"]
        }
    }
}
```

### System Prompts

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/system-prompts` | List system prompts |
| `POST` | `/system-prompts` | Create system prompt |
| `GET` | `/system-prompts/{id}` | Get system prompt |
| `PUT` | `/system-prompts/{id}` | Update system prompt |
| `DELETE` | `/system-prompts/{id}` | Delete system prompt |

#### Create System Prompt Example

```bash
POST /api/v1/system-prompts
Content-Type: application/json

{
    "name": "Base Instructions",
    "slug": "base-instructions",
    "template": "You are {{ $agent_name }}, a helpful assistant.\n\nToday is {{ $current_date }}.",
    "required_variables": ["agent_name"],
    "default_values": {},
    "is_active": true
}
```

### Files

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/files` | List user's files |
| `POST` | `/files` | Upload file |
| `GET` | `/files/{id}` | Get file metadata |
| `GET` | `/files/{id}/download` | Download file |
| `DELETE` | `/files/{id}` | Delete file |

#### Upload File

```bash
POST /api/v1/files
Content-Type: multipart/form-data

file: [binary data]
type: input
```

### AI Backends

| Method | Endpoint | Description |
|--------|----------|-------------|
| `GET` | `/ai-backends` | List available backends |
| `GET` | `/ai-backends/{driver}` | Get backend details |
| `GET` | `/ai-backends/{driver}/models` | List available models |
| `POST` | `/ai-backends/{driver}/models/pull` | Start model download |
| `GET` | `/ai-backends/{driver}/models/pull/{id}/stream` | Stream download progress |
| `GET` | `/ai-backends/{driver}/models/{model}` | Get model details |
| `DELETE` | `/ai-backends/{driver}/models/{model}` | Delete model |

#### List Backends

```bash
GET /api/v1/ai-backends
```

Response:

```json
[
    {
        "name": "Ollama",
        "driver": "ollama",
        "status": "available",
        "capabilities": {
            "streaming": true,
            "function_calling": true,
            "vision": true,
            "model_management": true
        }
    }
]
```

#### Pull Model

```bash
POST /api/v1/ai-backends/ollama/models/pull
Content-Type: application/json

{
    "model": "llama3.1"
}
```

Response:

```json
{
    "pull_id": "abc123",
    "stream_url": "/api/v1/ai-backends/ollama/models/pull/abc123/stream"
}
```

## Response Format

### Success Response

```json
{
    "data": {
        "id": 1,
        "name": "My Agent",
        "...": "..."
    }
}
```

### Collection Response

```json
{
    "data": [
        {"id": 1, "name": "Agent 1"},
        {"id": 2, "name": "Agent 2"}
    ],
    "links": {
        "first": "...",
        "last": "...",
        "prev": null,
        "next": "..."
    },
    "meta": {
        "current_page": 1,
        "from": 1,
        "last_page": 3,
        "per_page": 15,
        "to": 15,
        "total": 42
    }
}
```

### Error Response

```json
{
    "message": "The given data was invalid.",
    "errors": {
        "name": ["The name field is required."],
        "email": ["The email has already been taken."]
    }
}
```

## Error Codes

| Status Code | Meaning |
|-------------|---------|
| 200 | Success |
| 201 | Created |
| 204 | No Content (successful delete) |
| 400 | Bad Request |
| 401 | Unauthorized |
| 403 | Forbidden |
| 404 | Not Found |
| 422 | Validation Error |
| 429 | Too Many Requests |
| 500 | Server Error |

## Rate Limiting

API requests are rate-limited:

- **Default:** 60 requests per minute per user
- **Authentication:** 5 attempts per minute per IP

Rate limit headers:

```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
X-RateLimit-Reset: 1234567890
```

## Pagination

Collection endpoints support pagination:

```bash
GET /api/v1/agents?page=2&per_page=25
```

Parameters:
- `page` - Page number (default: 1)
- `per_page` - Items per page (default: 15, max: 100)

## Filtering

Some endpoints support filtering:

```bash
# Filter conversations by status
GET /api/v1/conversations?status=active

# Filter conversations by agent
GET /api/v1/conversations?agent_id=1

# Search system prompts
GET /api/v1/system-prompts?search=assistant
```

## API Documentation (Scribe)

If Scribe is configured, auto-generated API documentation is available at:

```
https://your-domain.com/docs
```

Generate docs:

```bash
php artisan scribe:generate
```

## Testing the API

### cURL Examples

```bash
# Login
TOKEN=$(curl -s -X POST https://example.com/api/v1/auth/login \
    -H "Content-Type: application/json" \
    -d '{"email":"user@example.com","password":"secret"}' \
    | jq -r '.token')

# List agents
curl -s https://example.com/api/v1/agents \
    -H "Authorization: Bearer $TOKEN"

# Create agent
curl -s -X POST https://example.com/api/v1/agents \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d '{"name":"Test Agent","ai_backend":"ollama"}'
```

### HTTPie Examples

```bash
# Login
http POST https://example.com/api/v1/auth/login \
    email=user@example.com password=secret

# List agents (with token)
http https://example.com/api/v1/agents \
    "Authorization: Bearer $TOKEN"
```

## WebSocket Events

For real-time updates, connect to the Reverb WebSocket server:

```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,
    forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
    enabledTransports: ['ws', 'wss'],
});

echo.private(`conversation.${conversationId}`)
    .listen('MessageReceived', (e) => {
        console.log(e.message);
    });
```

## Next Steps

- [Configuration](configuration.md) - API configuration options
- [Troubleshooting](troubleshooting.md) - Common API issues
- [Security](security.md) - API security best practices
