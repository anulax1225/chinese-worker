# Chinese Worker Architecture Plan

## Overview

This document outlines the complete architecture plan for Chinese Worker - an AI agent execution platform with server-managed agentic loops and CLI-based builtin tool execution.

## Core Architecture Principle

**Server runs the agentic loop. CLI executes builtin tools locally.**

- **Server**: Manages conversation state, runs AI loop, executes system/user tools
- **CLI**: Polls for status, executes builtin tools (bash, read, write, etc.), submits results
- **Communication**: HTTP polling (Phase 2), SSE (Phase 4), WebSocket (Phase 5)

## Tool Categories

### 1. Builtin Tools (CLI-executed)
Execute on the user's local machine via CLI:
- **bash**: Execute shell commands
- **read**: Read files from filesystem
- **write**: Write files to filesystem
- **edit**: Perform string replacements in files
- **glob**: Find files matching patterns
- **grep**: Search file contents with regex

### 2. System Tools (Server-executed)
Execute on server, manage agent state:
- **todo_add**: Add todo item
- **todo_list**: List todos
- **todo_complete**: Mark todo complete
- **todo_update**: Update todo
- **todo_delete**: Delete todo
- **todo_clear**: Clear all todos

### 3. User Tools (Server-executed - Future)
Custom tools created by users:
- **HTTP tools**: Make HTTP requests
- **Webhook tools**: Receive webhooks
- **Code tools**: Execute sandboxed code

## Implementation Phases

### Phase 1: Backend with Polling ✅ COMPLETE
**Duration**: Week 1

**Deliverables**:
1. **Database Schema**
   - `conversations` table with full conversation state
   - `messages` JSON column for history
   - Status tracking (active, paused, completed, failed)
   - Metadata for extensibility

2. **Backend API**
   - ConversationController with REST endpoints
   - ConversationService with server-managed agentic loop
   - System tool implementations (6 todo operations)
   - Polling endpoint for CLI status checks

3. **Models & DTOs**
   - Conversation model with helper methods
   - ConversationState DTO for status responses
   - ToolCall, ToolResult, AIResponse DTOs
   - ConversationResource for API responses

4. **Policies & Authorization**
   - ConversationPolicy for access control
   - Users can only access their own conversations

5. **Testing**
   - Comprehensive test suite (22+ tests)
   - ConversationFactory for test data
   - All tests passing

**Key Endpoints**:
```
POST   /api/v1/agents/{agent}/conversations         - Create conversation
POST   /api/v1/conversations/{id}/messages          - Send message
GET    /api/v1/conversations/{id}/status            - Poll status
POST   /api/v1/conversations/{id}/tool-results      - Submit tool result
GET    /api/v1/conversations/{id}                   - Get conversation
GET    /api/v1/conversations                        - List conversations
DELETE /api/v1/conversations/{id}                   - Delete conversation
```

### Phase 2: CLI with Polling ✅ COMPLETE
**Duration**: Week 2

**Deliverables**:
1. **Project Structure**
   - Python package in `cli/` folder
   - pyproject.toml with dependencies
   - CLI entry point via click

2. **API Client**
   - HTTP client using httpx
   - Authentication with JSON file storage
   - All backend endpoints implemented

3. **Builtin Tools**
   - 6 tools fully implemented
   - Base tool class for consistency
   - Error handling and validation

4. **CLI Commands**
   - `cw login` - Authenticate
   - `cw logout` - Clear credentials
   - `cw whoami` - Show current user
   - `cw agents` - List agents
   - `cw chat <agent_id>` - Start chat with polling

5. **Rich Terminal UI**
   - Progress spinners
   - Markdown rendering
   - Color-coded output
   - Panels and formatting

**Communication Flow**:
1. User sends message via CLI
2. Server processes with AI, runs loop
3. CLI polls `/status` endpoint every 2s
4. Server returns `waiting_for_tool` when builtin tool needed
5. CLI executes tool locally
6. CLI submits result to `/tool-results`
7. Server resumes loop with tool result
8. Repeat until completion

### Phase 2.5: CLI Polish ✅ COMPLETE
**Duration**: Week 2.5

**Focus**: Production-ready CLI with excellent UX

**Deliverables**:
1. **Persistent Loop**
   - Conversations run indefinitely
   - Only stop on explicit user exit
   - Error recovery without termination

2. **Conversation Management**
   - `cw conversations` command
   - Interactive conversation selection
   - Resume existing conversations
   - Display conversation history

3. **Robust Error Handling**
   - Graceful degradation
   - Clear error messages
   - Recovery suggestions
   - Never crashes

4. **Enhanced UX**
   - Visual feedback for all operations
   - Tool execution status display
   - Markdown rendering for responses
   - Beautiful tables and formatting

5. **Documentation**
   - Updated README
   - Quick start guide
   - Tool testing script
   - Phase 2.5 summary

### Phase 3: User Tools & Advanced Features 🔜 NEXT
**Duration**: Week 3-4

**Deliverables**:
1. **HTTP Tools**
   - Create HTTP request tools via UI
   - Configure method, URL, headers, body
   - Execute on server
   - Return response to AI

2. **Webhook Tools**
   - Create webhook endpoints
   - Receive and store webhook data
   - Queue for AI processing
   - Respond to webhooks

3. **Code Tools (Sandboxed)**
   - Execute Python/Node.js code
   - Sandboxed environment
   - Resource limits (CPU, memory, time)
   - Security restrictions

4. **Tool Management UI**
   - Create/edit/delete tools
   - Test tool execution
   - View execution history
   - Share tools between agents

5. **Enhanced Agent Configuration**
   - Configure which tools agent can use
   - Tool-specific settings
   - Permission management

### Phase 4: Server-Sent Events (SSE) 🔜 FUTURE
**Duration**: Week 5

**Why**: Replace polling with real-time streaming

**Deliverables**:
1. **SSE Endpoint**
   - Stream conversation events
   - Tool requests sent as events
   - Completion/error events

2. **CLI SSE Client**
   - Connect to SSE stream
   - Handle events in real-time
   - Fallback to polling if SSE unavailable

3. **Benefits**:
   - Instant updates (no polling delay)
   - Reduced server load
   - Better user experience

### Phase 5: WebSocket (Advanced) 🔜 FUTURE
**Duration**: Week 6

**Why**: Full bidirectional real-time communication

**Deliverables**:
1. **WebSocket Server**
   - Laravel Reverb integration
   - Conversation channels
   - Real-time events

2. **CLI WebSocket Client**
   - Connect to WebSocket
   - Send/receive messages
   - Auto-reconnect

3. **Advanced Features**:
   - Multi-user conversations
   - Real-time collaboration
   - Streaming AI responses

### Phase 6: Multi-Agent Orchestration 🔜 FUTURE
**Duration**: Week 7-8

**Why**: Complex workflows requiring multiple agents

**Deliverables**:
1. **Agent Workflows**
   - Define multi-agent pipelines
   - Agent-to-agent communication
   - Shared context/memory

2. **Orchestrator**
   - Route tasks to appropriate agents
   - Aggregate results
   - Handle failures

3. **Use Cases**:
   - Research + Writing pipeline
   - Code review + Fix pipeline
   - Data analysis + Visualization

## Database Schema

### conversations table
```php
id: bigint
agent_id: foreignId -> agents
user_id: foreignId -> users
status: enum(active, paused, completed, failed, cancelled)
messages: json                      // Full conversation history
metadata: json                      // Extensible metadata
turn_count: integer                 // Number of AI turns
total_tokens: integer               // Token usage
started_at: timestamp
last_activity_at: timestamp
completed_at: timestamp
cli_session_id: string              // Track CLI sessions
waiting_for: string                 // What server is waiting for
pending_tool_request: json          // Tool request awaiting execution
created_at: timestamp
updated_at: timestamp

indexes:
- (agent_id, status)
- (user_id, status)
- (status)
- (last_activity_at)
```

### agents table (existing + additions)
```php
// Existing fields...
metadata: json                      // NEW: Store agent state (todos, etc.)
```

## API Response Formats

### Conversation Status Response
```json
{
  "status": "waiting_for_tool|processing|completed|failed",
  "conversation_id": 123,
  "tool_request": {                // If waiting_for_tool
    "call_id": "tool_abc123",
    "name": "bash",
    "arguments": {"command": "ls -la"}
  },
  "submit_url": "/api/v1/conversations/123/tool-results",
  "error": "Error message",        // If failed
  "final_response": "...",         // If completed
  "check_url": "/api/v1/conversations/123/status"
}
```

### Tool Result Submission
```json
{
  "call_id": "tool_abc123",
  "success": true,
  "output": "file1.txt\nfile2.txt",
  "error": null
}
```

## Security Considerations

### Authentication
- Sanctum API tokens
- JSON file storage on CLI (single-user environment)
- Token refresh on expiry

### Authorization
- Users can only access their own conversations
- Agents belong to users
- Policy-based access control

### Tool Execution Safety
- Builtin tools run in user's environment (user responsibility)
- System tools have input validation
- User tools (future) run in sandboxed environments
- Rate limiting on API endpoints
- Max turns limit (25) to prevent infinite loops

## Performance Considerations

### Database
- Index on common query patterns
- JSON columns for flexibility
- Pagination for list endpoints

### Caching
- No caching in Phase 1-2 (premature)
- Consider Redis for Phase 3+ (active conversations)

### Scalability
- Stateless API (horizontal scaling)
- Background jobs for long-running operations (future)
- Queue system for high-volume tool execution (future)

## Error Handling

### Server Errors
- Conversation marked as "failed"
- Error message stored
- User can retry or abandon

### Tool Errors
- Tool failure submitted as error result
- AI can recover and try alternative approach
- Max consecutive errors before conversation fails

### CLI Errors
- Network failures: Retry with exponential backoff
- Tool execution failures: Submit error to server
- User can always continue or exit

## Future Enhancements (Beyond Phase 6)

1. **Voice Interface**: CLI with voice input/output
2. **Mobile App**: Native iOS/Android apps
3. **Multi-LLM Support**: OpenAI, Anthropic, local models
4. **Agent Marketplace**: Share and discover agents
5. **Team Collaboration**: Shared agents and conversations
6. **Analytics Dashboard**: Usage statistics and insights
7. **Agent Templates**: Pre-built agents for common tasks
8. **Integration Hub**: Connect to external services (GitHub, Slack, etc.)

## Technology Stack

**Backend**:
- Laravel 12
- PHP 8.5
- MySQL/PostgreSQL
- Sanctum authentication
- Pest testing

**CLI**:
- Python 3.10+
- Click (CLI framework)
- httpx (HTTP client)
- Rich (terminal UI)

**Future**:
- Laravel Reverb (WebSockets)
- Redis (caching)
- Docker (sandboxing)
- Vue.js (web UI)

## Success Metrics

- **Phase 1**: All tests passing, API functional
- **Phase 2**: CLI can execute tools and complete conversations
- **Phase 2.5**: Production-ready CLI with excellent UX
- **Phase 3**: Users can create custom tools
- **Phase 4**: Real-time updates via SSE
- **Phase 5**: Full bidirectional WebSocket communication
- **Phase 6**: Multi-agent workflows operational

## Conclusion

This architecture provides a solid foundation for building a powerful AI agent platform with flexible tool execution and excellent developer experience. The phased approach allows for iterative development and validation at each stage.
