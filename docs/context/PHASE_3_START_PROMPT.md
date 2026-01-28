# Phase 3 Start Prompt

Copy and paste this prompt into a new conversation with Claude to start Phase 3.

---

# Continue Chinese Worker Development - Phase 3

I'm working on **Chinese Worker**, an AI agent execution platform. I need you to continue development on **Phase 3: User Tools & Advanced Features**.
Go read docs/context/*.md for more informations
## Project Context

**Project**: Chinese Worker - AI agent execution platform
**Location**: `/home/anulax/chinese-worker`
**Tech Stack**: Laravel 12, PHP 8.5, Python 3.10, MySQL/PostgreSQL

## Architecture Overview

**Core Principle**: Server runs the agentic loop. CLI executes builtin tools locally.

- **Server**: Manages conversation state, runs AI loop via `ConversationService`
- **CLI**: Polls for status, executes builtin tools (bash, read, write, edit, glob, grep), submits results
- **Communication**: HTTP polling (will be SSE in Phase 4)

### Tool Categories

1. **Builtin Tools** (CLI-executed): bash, read, write, edit, glob, grep
2. **System Tools** (Server-executed): todo_add, todo_list, todo_complete, todo_update, todo_delete, todo_clear
3. **User Tools** (Phase 3 - TO BUILD): HTTP, Webhook, Code tools

## What's Complete

### ✅ Phase 1: Backend with Polling
- Complete backend API with ConversationController
- ConversationService with server-managed agentic loop
- Database schema with conversations table
- 6 system tools (todo operations) implemented
- 22 passing tests
- All endpoints working

**Key Files**:
- `app/Services/ConversationService.php` - Core agentic loop
- `app/Http/Controllers/Api/V1/ConversationController.php` - API endpoints
- `app/Models/Conversation.php` - Conversation model
- `tests/Feature/Api/V1/ConversationTest.php` - Test suite

### ✅ Phase 2: CLI with Polling
- Full Python CLI in `cli/` folder
- API client with authentication
- All 6 builtin tools implemented and working
- Commands: login, logout, whoami, agents, chat

### ✅ Phase 2.5: CLI Polish
- Persistent conversation loop (runs until user exits)
- Conversation management (list, select, resume)
- Robust error handling
- Excellent UX with Rich terminal UI
- NEW command: `cw conversations`
- Production-ready

**Key Files**:
- `cli/chinese_worker/cli.py` - Main CLI (500+ lines)
- `cli/chinese_worker/api/client.py` - API client
- `cli/chinese_worker/tools/*.py` - Builtin tools

## Phase 3 Objectives

Build **User Tools** - custom tools created by users that execute on the server.

### 1. HTTP Tools
- Users can create HTTP request tools via API/UI
- Configure method (GET, POST, etc.), URL, headers, body
- Execute on server during agent conversations
- Return HTTP response to AI for processing

**Example Use Cases**:
- Check website status
- Query external APIs
- Webhook notifications
- Data fetching

### 2. Webhook Tools
- Users can create webhook endpoints
- Receive and store webhook data
- Queue webhooks for AI processing
- Respond to webhooks with custom logic

**Example Use Cases**:
- GitHub webhooks (PR created, issues, etc.)
- Stripe webhooks (payment events)
- Custom integrations

### 3. Code Tools (Sandboxed)
- Execute Python/Node.js code snippets
- Sandboxed environment for security
- Resource limits (CPU, memory, timeout)
- Return execution result to AI

**Example Use Cases**:
- Data transformation
- Custom calculations
- Text processing
- API response parsing

### 4. Tool Management
- API endpoints to create/edit/delete tools
- UI or CLI commands for tool management
- Test tool execution before using in agents
- View tool execution history

### 5. Agent Tool Configuration
- Configure which tools each agent can use
- Tool-specific settings per agent
- Permission management

## Database Schema (To Add)

### tools table (existing - to extend)
```sql
-- Already exists with name, description, type, config
-- Add support for:
type: enum('http', 'webhook', 'code', ...)
config: json with tool-specific configuration
is_builtin: boolean (false for user tools)
```

### tool_executions table (new)
```sql
id
tool_id
conversation_id
agent_id
user_id
input: json
output: json
success: boolean
error_message: text
execution_time_ms: integer
created_at
```

### webhooks table (new)
```sql
id
tool_id
user_id
url: string (generated webhook URL)
secret: string (for verification)
last_received_at: timestamp
created_at
```

### webhook_events table (new)
```sql
id
webhook_id
payload: json
headers: json
ip_address: string
processed: boolean
processed_at: timestamp
created_at
```

## Implementation Checklist

### Backend
- [ ] Extend Tool model for user tools
- [ ] Create ToolExecution model for history
- [ ] Create Webhook & WebhookEvent models
- [ ] Add HTTP tool executor to ConversationService
- [ ] Add Webhook tool executor
- [ ] Add Code tool executor (with sandboxing)
- [ ] Create ToolController for CRUD operations
- [ ] Create WebhookController for receiving webhooks
- [ ] Add tool execution to agentic loop
- [ ] Write tests for all new features
- [ ] Add migrations for new tables

### Frontend/CLI (Optional for Phase 3)
- [ ] Add tool management commands to CLI
- [ ] `cw tools list` - List tools
- [ ] `cw tools create-http` - Create HTTP tool
- [ ] `cw tools create-webhook` - Create webhook tool
- [ ] `cw tools test <id>` - Test tool execution

### Security
- [ ] Sandbox code execution (Docker/Firecracker)
- [ ] Rate limiting on tool execution
- [ ] Webhook signature verification
- [ ] Input validation and sanitization
- [ ] Timeout enforcement

## Important Context

### Conversation Flow with User Tools
1. AI requests tool (e.g., `http_tool_123`)
2. ConversationService detects user tool (not builtin, not system)
3. Server executes tool directly (no CLI pause)
4. Tool makes HTTP request / receives webhook / executes code
5. Result returned to AI
6. Loop continues

### Tool Execution Location
- **Builtin**: CLI (user's machine)
- **System**: Server (immediate)
- **User**: Server (may be async for webhooks)

### Code Sandboxing Options
1. **Docker containers** - Isolated environment
2. **Firecracker microVMs** - Lightweight VMs
3. **gVisor** - Application kernel
4. **Simple PHP exec** - Basic (not recommended for production)

Start with simple approach, add proper sandboxing later.

## Files to Reference

**Documentation**:
- `/docs/context/PLAN.md` - Full architecture plan
- `/docs/context/CURRENT_STATE.md` - Detailed current state
- `/QUICKSTART.md` - User getting started guide
- `/cli/README.md` - CLI documentation

**Key Backend Files**:
- `app/Services/ConversationService.php` - Agentic loop (lines 200-400)
- `app/Http/Controllers/Api/V1/ConversationController.php` - API endpoints
- `app/Models/Agent.php`, `app/Models/Tool.php` - Existing models
- `routes/api.php` - API routes

**Key CLI Files**:
- `cli/chinese_worker/cli.py` - Main CLI
- `cli/chinese_worker/tools/base.py` - Tool base class pattern

## Laravel Conventions

This is a Laravel 12 project. Follow Laravel conventions:
- Use `php artisan make:` commands for generating files
- Eloquent for database operations
- Form Requests for validation
- API Resources for responses
- Policies for authorization
- Pest for testing

## Testing Requirements

**CRITICAL**: Every change must be tested.
- Write tests first or alongside implementation
- Run tests frequently: `./vendor/bin/sail artisan test`
- Ensure all existing tests still pass
- Aim for >80% code coverage

## Your Task

Start implementing Phase 3: User Tools & Advanced Features.

**Recommended Order**:
1. Start with HTTP tools (simplest)
2. Add webhook tools
3. Add code tools (with basic sandboxing)
4. Add tool management API
5. Add CLI commands (optional)
6. Write comprehensive tests

**First Steps**:
1. Read the existing code to understand patterns
2. Create migrations for new tables
3. Extend/create models for user tools
4. Add HTTP tool executor to ConversationService
5. Create ToolController for management
6. Write tests

Ask clarifying questions if needed. Use the TodoWrite tool to track progress.

Let's build Phase 3! 🚀

---

## Additional Commands

```bash
# Run migrations
./vendor/bin/sail artisan migrate

# Run tests
./vendor/bin/sail artisan test

# Run specific test
./vendor/bin/sail artisan test tests/Feature/Api/V1/ConversationTest.php

# Code formatting
./vendor/bin/sail exec laravel.test vendor/bin/pint

# CLI testing
cd cli && source .venv/bin/activate
python test_tools.py
```
