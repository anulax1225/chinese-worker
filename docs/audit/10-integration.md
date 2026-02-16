# Cross-System Integration Audit

## Overview

This audit covers integration aspects across all three systems (Backend, Python CLI, Vue Web App) including API contract consistency, SSE/WebSocket integration, error handling across boundaries, and authentication flow.

## Critical Files

### Backend
| Category | Path |
|----------|------|
| API Resources | `app/Http/Resources/` |
| API Routes | `routes/api.php` |
| Broadcasting | `app/Services/ConversationEventBroadcaster.php` |
| Channel Auth | `routes/channels.php` |
| API Documentation | `docs/guide/api-overview.md` |

### Python CLI
| Category | Path |
|----------|------|
| API Client | `cw-cli/chinese_worker/api/client.py` |
| SSE Client | `cw-cli/chinese_worker/api/sse_client.py` |
| Tool Handler | `cw-cli/chinese_worker/tui/handlers/tool_handler.py` |

### Web Application
| Category | Path |
|----------|------|
| Type Definitions | `resources/js/types/` |
| Composables | `resources/js/composables/` |
| Wayfinder | Vite plugin configuration |

---

## Checklist

### 1. API Contract Consistency

#### 1.1 Response Format
- [x] **Consistent envelope** - Verify response structure
  - Reference: `app/Http/Resources/*`
  - Finding: All resources return data via `JsonResource`. Collections use Laravel's pagination with `data`, `meta`, `links` structure.

- [x] **Error format consistent** - Verify error responses
  - Reference: `docs/guide/api-overview.md:412-419`
  - Finding: Laravel validation errors use `{ message: "", errors: {} }` format. Documented in API overview.

- [x] **Resource transformation** - Verify resources
  - Reference: `app/Http/Resources/`
  - Finding: 13 resource classes with consistent structure. All use `toArray()` method.

#### 1.2 TypeScript Types Match Backend
- [x] **Agent type matches** - Verify Agent typing
  - Reference: `resources/js/types/models.ts:24-39` vs `app/Http/Resources/AgentResource.php`
  - Finding: **MISMATCH** - TypeScript has `user_id: number` but AgentResource doesn't include `user_id`, uses `'user' => $this->whenLoaded('user')` instead. Documented in INT-001.

- [x] **Conversation type matches** - Verify Conversation typing
  - Reference: `resources/js/types/models.ts:188-209` vs `app/Http/Resources/ConversationResource.php`
  - Finding: Types match. Both include status, messages, token_usage, pending_tool_request, client_type, etc.

- [x] **Tool type matches** - Verify Tool typing
  - Reference: `resources/js/types/models.ts:57-69`
  - Finding: Tool types match. Includes id, user_id, name, type, config, description, parameters.

- [x] **User type matches** - Verify User typing
  - Reference: `resources/js/types/models.ts:1-9` vs `app/Http/Resources/UserResource.php`
  - Finding: Core fields match (id, name, email, email_verified_at, created_at, updated_at).

#### 1.3 CLI Client Types
- [x] **CLI handles all response types** - Verify Python client
  - Reference: `cw-cli/chinese_worker/api/client.py`
  - Finding: CLI uses `Dict[str, Any]` for responses. Handles JSON correctly but no strict typing.

- [x] **Error handling matches API** - Verify error parsing
  - Reference: `cw-cli/chinese_worker/api/client.py:55,110`
  - Finding: Uses `response.raise_for_status()` which raises `httpx.HTTPStatusError` for 4xx/5xx. Error message accessible via exception.

---

### 2. SSE Event Format Consistency

#### 2.1 Event Types
- [x] **text_chunk event** - Verify format
  - Backend: `ConversationEventBroadcaster.php:51-58`
  - Format: `{ chunk: string, type: 'content' | 'thinking', conversation_id: number }`
  - Finding: Consistent across all clients.

- [x] **tool_request event** - Verify format
  - Backend: `ConversationEventBroadcaster.php:65-77`
  - Format: `{ status, conversation_id, tool_request: { call_id, name, arguments }, submit_url, stats }`
  - Finding: Both clients extract `tool_request` correctly.

- [x] **tool_executing event** - Verify format
  - Backend: `ConversationEventBroadcaster.php:84-94`
  - Format: `{ conversation_id, tool: { call_id, name, arguments } }`
  - Finding: Web and CLI both handle this event.

- [x] **tool_completed event** - Verify format
  - Backend: `ConversationEventBroadcaster.php:99-108`
  - Format: `{ conversation_id, call_id, name, success, content }`
  - Finding: Consistent handling in both clients.

- [x] **completed event** - Verify format
  - Backend: `ConversationEventBroadcaster.php:113-132`
  - Format: `{ status: 'completed', conversation_id, stats: { turns, tokens }, messages? }`
  - Finding: Both clients handle completion and extract stats.

- [x] **failed event** - Verify format
  - Backend: `ConversationEventBroadcaster.php:137-148`
  - Format: `{ status: 'failed', conversation_id, error, stats }`
  - Finding: Error message extracted by both clients.

#### 2.2 Client Handling
- [x] **Web app handles all events** - Verify Vue handling
  - Reference: `resources/js/composables/useConversationStream.ts:37-124`
  - Finding: Handles: connected, text_chunk, tool_executing, tool_completed, tool_request, completed, failed, cancelled, status_changed.

- [x] **CLI handles all events** - Verify Python handling
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:137-205`
  - Finding: SSEEventHandler handles all event types including unknown events (returns True to continue).

- [x] **Unknown events handled** - Verify forward compatibility
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:205`
  - Finding: `return True` for unknown events - continues listening without crash.

---

### 3. Tool Schema Agreement

#### 3.1 Schema Format
- [x] **JSON Schema consistent** - Verify tool schemas
  - Finding: Tools use standard JSON Schema format for parameters. Backend validates against schema.

- [x] **CLI tools match backend** - Verify client tool schemas
  - Reference: `cw-cli/chinese_worker/tools/`
  - Finding: CLI tools implement `get_schema()` returning JSON Schema. Schema sent to backend on conversation creation via `client_tool_schemas`.

#### 3.2 Tool Execution Flow
- [x] **Request format consistent** - Verify tool request
  - Finding: `{ call_id, name, arguments }` format used consistently.

- [x] **Result format consistent** - Verify tool result
  - Backend: `submitToolResult` expects `{ call_id, success, output, error }`
  - CLI: `submit_tool_result()` sends same format
  - Web: `submitToolResultAction` sends same format

- [x] **Client tools registered** - Verify client tool registration
  - Reference: `cw-cli/chinese_worker/api/client.py:162`
  - Finding: `client_tool_schemas` sent with conversation creation.

---

### 4. Authentication Flow

#### 4.1 Web App Authentication
- [x] **Inertia/Sanctum flow** - Verify web auth
  - Finding: SPA uses Sanctum cookie-based authentication. CSRF token in meta tag.

- [x] **2FA integration** - Verify 2FA flow
  - Finding: Fortify configured. 2FA routes available via Laravel standard.

#### 4.2 CLI Authentication
- [x] **Token-based auth** - Verify CLI auth
  - Reference: `cw-cli/chinese_worker/api/auth.py`
  - Finding: Token stored in `~/.chinese-worker/token.json`. Used for all API requests.

- [x] **Login flow** - Verify login
  - Reference: `cw-cli/chinese_worker/api/client.py:36-61`
  - Finding: `POST /api/v1/auth/login` returns token, stored by AuthManager.

- [x] **Token in headers** - Verify header format
  - Reference: `cw-cli/chinese_worker/api/client.py:32`
  - Finding: `Authorization: Bearer {token}` format correctly implemented.

#### 4.3 SSE Authentication
- [x] **SSE stream auth** - Verify stream authentication
  - Web: Uses cookie-based auth via EventSource (cookies sent automatically)
  - CLI: Uses Bearer token in headers (`cw-cli/chinese_worker/api/sse_client.py:28-32`)
  - Finding: Both authentication methods work.

#### 4.4 Session Expiry
- [x] **Web session expiry** - Verify handling
  - Finding: Sanctum session expiry handled by Laravel. Inertia redirects to login on 401.

- [x] **CLI token expiry** - Verify handling
  - Finding: **NO TOKEN REFRESH** - CLI doesn't handle token expiry gracefully. 401 errors propagate up without automatic re-auth prompt. Documented in INT-003.

---

### 5. Error Response Standardization

#### 5.1 HTTP Error Codes
- [x] **400 Bad Request** - Verify usage
  - Finding: Used for malformed requests.

- [x] **401 Unauthorized** - Verify usage
  - Finding: Missing/invalid auth triggers 401.

- [x] **403 Forbidden** - Verify usage
  - Finding: Policy denials return 403.

- [x] **404 Not Found** - Verify usage
  - Finding: Resource not found returns 404.

- [x] **422 Validation Error** - Verify usage
  - Finding: Laravel Form Request validation failures return 422.

- [x] **429 Too Many Requests** - Verify usage
  - Reference: `docs/guide/api-overview.md:438-450`
  - Finding: Rate limiting configured (60/min default). Headers returned.

- [x] **500 Server Error** - Verify handling
  - Finding: Laravel exception handler sanitizes errors in production.

#### 5.2 Error Response Format
- [x] **Validation error format** - Verify structure
  - Finding: `{ message, errors: { field: [messages] } }` format used.

- [x] **General error format** - Verify structure
  - Finding: `{ message }` format for non-validation errors.

#### 5.3 Client Error Handling
- [x] **Web app error display** - Verify error UI
  - Finding: Errors displayed via console.error and pushed to messages array for system messages.

- [x] **CLI error display** - Verify error output
  - Finding: Uses Rich console with `[red]x[/red] Error:` formatting.

---

### 6. Pagination Consistency

#### 6.1 Pagination Format
- [x] **Meta structure** - Verify pagination meta
  - Reference: `docs/guide/api-overview.md:399-407`
  - Finding: Laravel standard: `{ meta: { current_page, from, last_page, per_page, to, total } }`

- [x] **Links structure** - Verify pagination links
  - Finding: `{ links: { first, last, prev, next } }` provided.

#### 6.2 Client Handling
- [x] **Web pagination** - Verify Vue handling
  - Finding: Inertia handles pagination. Components access paginated data via props.

- [x] **CLI pagination** - Verify Python handling
  - Reference: `cw-cli/chinese_worker/api/client.py:305-318`
  - Finding: `per_page` parameter supported. Returns `["data"]` from response.

---

### 7. WebSocket Integration (Reverb)

#### 7.1 Channel Authorization
- [x] **Private channel auth** - Verify channel auth
  - Reference: `routes/channels.php`
  - Finding: **NO CONVERSATION CHANNEL** - Only user channels defined (`App.Models.User.{id}`, `user.{userId}`). No `conversation.{id}` channel for WebSocket. Documented in INT-002. SSE is the primary streaming mechanism.

- [x] **Auth endpoint** - Verify broadcasting auth
  - Finding: Broadcasting auth endpoint exists via Laravel standard routing.

#### 7.2 Event Format
- [x] **Broadcast event format** - Verify event structure
  - Finding: **SSE PRIMARY** - WebSocket/Reverb not used for conversation streaming. SSE via Redis RPUSH/BLPOP is the real-time mechanism. Event format documented in Section 2 above.

- [x] **Client subscription** - Verify subscription
  - Finding: Web app uses EventSource for SSE, not Laravel Echo/WebSocket. CLI uses httpx streaming. Both connect to `/conversations/{id}/stream` endpoint.

---

### 8. File Upload Integration

#### 8.1 Upload Flow
- [x] **Multipart upload** - Verify upload format
  - Reference: `cw-cli/chinese_worker/api/client.py:787-801`
  - Finding: Both web and CLI use `multipart/form-data` for file uploads.

- [x] **Progress tracking** - Verify upload progress
  - Finding: No upload progress tracking implemented. httpx doesn't expose progress natively. Documented in INT-004.

#### 8.2 Download Flow
- [x] **Download endpoint** - Verify download
  - Reference: `routes/api.php:39`
  - Finding: `GET /files/{file}/download` endpoint exists.

- [x] **Content-Type handling** - Verify MIME types
  - Finding: MIME types stored in database, returned with appropriate headers.

---

### 9. Model Pull Integration

#### 9.1 Pull Initiation
- [x] **API endpoint** - Verify pull endpoint
  - Reference: `routes/api.php:55`
  - Finding: `POST /ai-backends/{backend}/models/pull` returns stream URL.

#### 9.2 Progress Streaming
- [x] **Progress format** - Verify progress events
  - Finding: `{ status, digest?, total?, completed?, percentage?, error? }` format documented.

- [x] **Completion handling** - Verify completion
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:278`
  - Finding: Both clients detect `completed` and `failed` terminal events.

---

### 10. API Versioning

#### 10.1 Version Consistency
- [x] **v1 prefix used** - Verify API versioning
  - Reference: `routes/api.php:15`
  - Finding: All endpoints under `Route::prefix('v1')`.

- [x] **Breaking change policy** - Document policy
  - Finding: No explicit policy documented, but v1 prefix allows future v2.

---

### 11. Documentation Sync

#### 11.1 API Documentation
- [x] **API docs match implementation** - Verify accuracy
  - Reference: `docs/guide/api-overview.md`
  - Finding: Documentation matches implementation. Minor format differences (e.g., tool result nesting in docs vs flat in code).

- [x] **OpenAPI/Scribe generated** - Verify auto-docs
  - Finding: Scribe mentioned in docs but not verified as configured. No `/docs` route observed. Documented in INT-006.

---

## Findings

| ID | Item | Severity | Finding | Status |
|----|------|----------|---------|--------|
| INT-001 | TypeScript Agent type mismatch | Low | TypeScript Agent has `user_id: number` but AgentResource returns `user` relation, not `user_id`. Frontend may expect field that doesn't exist. | Open |
| INT-002 | No WebSocket conversation channels | Medium | `channels.php` only defines user channels. No `conversation.{id}` channel for WebSocket-based real-time updates. SSE is sole mechanism. | Open |
| INT-003 | CLI token expiry not handled | Low | CLI doesn't detect or handle token expiry gracefully. 401 errors require manual re-login. | Open |
| INT-004 | No upload progress tracking | Low | Neither web nor CLI track upload progress for large files. | Open |
| INT-005 | API docs minor inconsistency | Low | Tool result submission format in docs shows nested `result` object, implementation uses flat `{ call_id, success, output, error }`. | Open |
| INT-006 | Scribe documentation not verified | Low | API docs mention Scribe but auto-generated documentation not observed at `/docs`. | Open |

---

## Recommendations

1. **Fix TypeScript Agent Type**: Update `resources/js/types/models.ts` to match AgentResource:
   ```typescript
   export interface Agent {
       id: number;
       // Remove user_id, use optional user relation
       user?: User;
       name: string;
       // ...
   }
   ```

2. **Add WebSocket Channels (Optional)**: If WebSocket fallback is needed:
   ```php
   // routes/channels.php
   Broadcast::channel('conversation.{conversationId}', function ($user, $conversationId) {
       return Conversation::where('id', $conversationId)
           ->where('user_id', $user->id)
           ->exists();
   });
   ```

3. **Add CLI Token Refresh Handling**: Detect 401 and prompt for re-login:
   ```python
   try:
       response = httpx.get(...)
       response.raise_for_status()
   except httpx.HTTPStatusError as e:
       if e.response.status_code == 401:
           console.print("[yellow]Session expired. Please login again.[/yellow]")
           # Clear stored token
           self.auth.clear_token()
   ```

4. **Add Upload Progress**: For CLI, use httpx's event hooks or streaming upload:
   ```python
   # Consider httpx_extensions or async upload with progress callback
   ```

5. **Update API Documentation**: Align tool result format in docs with actual implementation.

6. **Configure Scribe**: Generate OpenAPI documentation:
   ```bash
   composer require --dev knuckleswtf/scribe
   php artisan scribe:generate
   ```

## Summary

The integration between all three systems (Backend, CLI, Web App) is **well-aligned** with a few minor issues:

**Strengths**:
- Consistent SSE event format across backend and both clients
- All SSE event types handled by both clients
- Proper authentication flow for both web (cookie) and CLI (token)
- API versioning in place with `/api/v1` prefix
- Comprehensive API documentation exists
- Tool schema format consistent across systems
- Error response format standardized

**Weaknesses**:
- Minor TypeScript type mismatch for Agent
- No WebSocket channels for conversations (SSE-only)
- CLI doesn't handle token expiry gracefully
- No upload progress tracking

The systems communicate effectively. The identified issues are low-to-medium severity and don't impact core functionality.
