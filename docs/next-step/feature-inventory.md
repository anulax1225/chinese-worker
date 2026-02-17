# Feature Inventory

Complete inventory of every feature available in Chinese Worker today.

---

## 1. AI Backends

Six AI backends are configured and ready to use. Each agent can select its own backend independently.

| Backend | Driver | Default Model | Type | Best For |
|---------|--------|---------------|------|----------|
| **ollama** | ollama | `llama3.1` | Local | General use, privacy, no API costs |
| **vllm-gpu** | vllm | `meta-llama/Llama-3.1-8B-Instruct` | Local (GPU) | High-throughput inference with NVIDIA/AMD GPU |
| **vllm-cpu** | vllm | `meta-llama/Llama-3.2-3B-Instruct` | Local (CPU) | Machines without GPU, lighter workloads |
| **claude** | anthropic | `claude-sonnet-4-5-20250929` | Cloud | Complex reasoning, long context |
| **openai** | openai | `gpt-4` | Cloud | General cloud AI |
| **huggingface** | huggingface | `meta-llama/Llama-3.1-8B-Instruct` | Cloud | Access to HuggingFace model hub |

**Per-agent model configuration overrides:**
- `temperature`, `max_tokens`, `context_length`, `topP`, `topK`
- Configuration merging: driver defaults -> global config -> agent overrides

**Model management API:**
- List available models per backend
- Pull/download new models (Ollama)
- Delete models
- Stream download progress via SSE

**Config:** `config/ai.php` > `backends`

---

## 2. Agent System

Agents are the core unit of the platform. Each agent is independently configurable.

**What you can configure per agent:**
- Name and description
- AI backend selection (any of the 6 above)
- Model config overrides (temperature, max_tokens, context_length, topP, topK)
- System prompts: ordered set of Blade templates attached to the agent
- Tools: choose which tools the agent has access to
- Context variables: custom key-value pairs available in prompt templates
- Context strategy: `token_budget` (default), `sliding_window`, `noop`, or `summary_boundary`
- Context strategies: array of strategies to run as a pipeline (e.g., `["summary_boundary", "token_budget"]`)
- Context threshold: when to trigger filtering (0.0-1.0, default 0.8)

**System prompt templating:**
- Blade syntax: `{{ $variable }}`
- Automatic variables: `$agent_name`, `$agent_description`, `$current_date`, `$current_time`, `$current_datetime`
- Custom variables from agent's `context_variables` JSON
- Multiple prompts per agent with explicit ordering
- Per-prompt variable overrides via pivot table

**API:** Full CRUD at `/api/v1/agents` + tool attachment endpoints
**UI:** Agent list, create, show, edit pages

---

## 3. Conversations & Streaming

Multi-turn stateful conversations between users and agents.

**Conversation lifecycle:**
- `active` -> `paused` (waiting for tool result) -> `active` -> `completed` or `failed`
- Also: `waiting_for_tool` status for client-side tool execution
- Maximum turns per conversation: 25 (configurable via `AGENT_MAX_TURNS`)

**Agentic loop** (`ProcessConversationTurn` job):
1. Load agent + relationships
2. Assemble system prompt from templates
3. Apply context filtering if approaching limit
4. Call AI backend with streaming
5. Process tool calls from response
6. Execute system tools immediately / pause for client tools
7. Dispatch next turn if tools were executed
8. Repeat until completion or max turns

**Real-time streaming:**
- **SSE:** `GET /api/v1/conversations/{id}/stream` for live text chunks
- **WebSocket:** Laravel Reverb for push-based updates
- **Polling:** `GET /api/v1/conversations/{id}/status` as fallback

**Events broadcast:**
- `text_chunk` - AI response text as it's generated
- `tool_request` - Agent wants to call a tool
- `tool_executing` - Server executing a system tool
- `tool_completed` - Tool finished
- `completed` - Conversation turn done
- `failed` - Error occurred

**Token tracking:**
- Prompt tokens + completion tokens per turn
- Context usage percentage monitoring
- Snapshots of system prompt and model config on first turn

**API:** Create, list, show, send message, stream, poll status, submit tool results, stop, delete
**UI:** Conversation list and real-time chat interface

---

## 4. Tool Framework

15 system tools always available + custom user-defined tools.

### System Tools (server-executed, always available)

#### Todo Management (6 tools)
Agents can track tasks within conversations.

| Tool | Parameters | What it does |
|------|-----------|--------------|
| `todo_add` | `item` (required), `priority` (low/medium/high) | Add a new task |
| `todo_list` | _(none)_ | List all tasks |
| `todo_complete` | `id` (required) | Mark task done |
| `todo_update` | `id`, `item` (both required) | Update task text |
| `todo_delete` | `id` (required) | Remove a task |
| `todo_clear` | _(none)_ | Clear all tasks |

#### Web Tools (2 tools)
Agents can search the web and fetch page content.

| Tool | Parameters | What it does |
|------|-----------|--------------|
| `web_search` | `query` (required), `max_results` (default 5) | Search via SearXNG metasearch |
| `web_fetch` | `url` (required) | Fetch and extract readable content from URL |

#### Document Tools (5 tools)
Available only when documents are attached to a conversation.

| Tool | Parameters | What it does |
|------|-----------|--------------|
| `document_list` | _(none)_ | List all attached documents with IDs and stats |
| `document_info` | `document_id` (required) | Get metadata, sections, chunk count |
| `document_get_chunks` | `document_id`, `start_index` (required), `end_index` | Read specific chunks (max 10 at a time) |
| `document_read_file` | `document_id` (required) | Read entire document content (small/medium docs) |
| `document_search` | `query` (required), `document_id`, `max_results` (max 10) | Search text within attached documents |

#### Conversation Memory Tools (2 tools)
Available only when RAG is enabled. Search previous conversation messages using semantic similarity.

| Tool | Parameters | What it does |
|------|-----------|--------------|
| `conversation_recall` | `query` (required), `max_results` (default 5), `threshold` (0-1) | Semantic search in conversation history |
| `conversation_memory_status` | _(none)_ | Check how many messages are indexed for search |

### Client Tools (CLI-executed)
These tools pause the conversation and request execution from the connected client (CLI/UI):
- `bash` - Execute shell commands
- `read` - Read file contents
- `write` - Write to files
- `edit` - Edit files with find/replace
- `glob` - Find files by pattern
- `grep` - Search file contents

### Custom API Tools
User-defined HTTP tools attached per-agent:
- Define endpoint URL, method, headers, parameters
- JSON schema for LLM awareness
- SSRF protection (configurable `TOOLS_BLOCK_PRIVATE_IPS`)

**Tool argument validation** against JSON schemas before execution.

**Config:** `config/agent.php` (security, timeouts, limits)
**Source:** `app/Services/ToolSchemaRegistry.php`

---

## 5. Document Ingestion

Multi-format document processing with a 4-phase pipeline.

### Supported Formats (20 MIME types)

| Category | Formats |
|----------|---------|
| **Plain text** | `.txt`, `.md`, `.csv`, `.json`, `.xml` |
| **Documents** | `.pdf`, `.doc`, `.docx`, `.rtf`, `.odt` |
| **Spreadsheets** | `.xls`, `.xlsx`, `.ods` |
| **Presentations** | `.pptx` |
| **Web** | `.html`, `.xhtml` |
| **Images (OCR)** | `.jpg`, `.png`, `.gif` |

### Processing Pipeline

| Phase | What it does | Details |
|-------|-------------|---------|
| **1. Extraction** | Extract raw text from file | PlainTextExtractor (text/*), TextractExtractor (PDF, Office, images) |
| **2. Cleaning** | Remove noise and normalize | 7 steps: encoding, whitespace, control chars, broken lines, headers/footers, boilerplate, quotes |
| **3. Normalization** | Detect structure | Heading detection, list normalization, paragraph boundaries |
| **4. Chunking** | Split into LLM-sized pieces | Default 1000 tokens/chunk, 100 token overlap, respects section boundaries |

**Document status flow:** `pending` -> `extracting` -> `cleaning` -> `normalizing` -> `chunking` -> `ready` (or `failed`)

**Ingestion sources:**
- File upload
- URL fetch
- Pasted text

**Features:**
- Reprocessing support (clears stages and chunks, re-runs pipeline)
- Stage metadata stored for debugging
- Automatic embedding generation triggered on completion (if RAG enabled)

**API:** CRUD + stages, chunks, preview, reprocess, supported-types
**UI:** Document list, create (upload/URL/paste), show with preview
**Config:** `config/document.php`

---

## 6. RAG System (Retrieval-Augmented Generation)

Enriches AI responses with relevant context from ingested documents.

**Toggle:** `RAG_ENABLED=true` in `.env` (default: false)

### Embedding Generation
- Model: `qwen3-embedding:0.6b` via Ollama (configurable)
- Dimensions: 1536 (configurable)
- Batch processing: 100 chunks per batch
- Stored in both JSON (`embedding_raw`) and pgvector (`embedding`) columns
- **Embedding cache:** SHA256 content hash avoids duplicate API calls
- Retry: 3 attempts with exponential backoff (60s, 300s, 900s)
- Automatic trigger: `EmbedDocumentChunksJob` dispatched when document processing completes

### Search Strategies

| Strategy | How it works | Best for |
|----------|-------------|----------|
| **Dense** (semantic) | pgvector cosine similarity on embeddings | Conceptual questions, paraphrased queries |
| **Sparse** (keyword) | PostgreSQL full-text search (`plainto_tsquery`) | Exact terms, technical names, specific phrases |
| **Hybrid** (default) | Reciprocal Rank Fusion combining both | General-purpose retrieval |

**Search parameters:**
- `RAG_TOP_K`: 10 results (configurable)
- `RAG_SIMILARITY_THRESHOLD`: 0.3 (configurable)
- `RAG_MAX_CONTEXT_TOKENS`: 4000 (configurable)

### Context Building
- `RAGContextBuilder` formats chunks with numbered citations: `[1] Document Title`
- Sources section appended with document titles and chunk references
- Token budget management (~50 tokens overhead per chunk)
- Options: `include_metadata`, `include_citations`, `max_context_tokens`

### Infrastructure Requirements
- PostgreSQL with `pgvector` extension (included in Docker setup)
- HNSW index on `document_chunks.embedding` for fast approximate nearest neighbor
- GIN index on `document_chunks.sparse_vector` for keyword search
- Full-text search index on `document_chunks.content`

**Retrieval analytics:** Every retrieval logged to `retrieval_logs` table with query, strategy, results, timing.

**Config:** `config/ai.php` > `rag`

---

## 7. Context Filtering

Automatic context management to prevent conversations from exceeding model context limits.

### Strategies

| Strategy | How it works | Config options |
|----------|-------------|---------------|
| **token_budget** (default) | Fits messages within calculated token budget | `budget_percentage` (0.8), `reserve_tokens` (1000) |
| **sliding_window** | Keeps N most recent messages | `window_size` (50) |
| **noop** | Pass-through, no filtering | _(none)_ |
| **summary_boundary** | Clips at latest completed summary, injects summary as context | _(read-only, uses existing summaries)_ |

### Strategy Pipeline

Agents can configure multiple strategies to run in sequence via `context_strategies` array:

```json
["summary_boundary", "token_budget"]
```

**Pipeline behavior:**
- Strategies run sequentially, each receiving the output of the previous
- Removed message IDs accumulate across all strategies
- `strategyUsed` field shows combined strategies (e.g., `summary_boundary+token_budget`)
- Falls back to single `context_strategy` if `context_strategies` is not set

**Key behaviors:**
- View-only filtering: original messages stay in database
- Preservation rules: system prompt, pinned messages, and tool call chains always kept
- Fail-open: sends all messages on error
- Per-agent configuration of strategy/strategies and threshold

**Token estimation ratios:**
- English prose: 4.0 chars/token
- Code: 3.0 chars/token
- JSON/structured: 2.5 chars/token
- Safety margin: 0.9 (10% buffer)

**Config:** `config/ai.php` > `context_filter`, `summarization`, `token_estimation`

---

## 8. Conversation Summaries

Client-driven API for creating and managing conversation summaries.

### Summary Lifecycle

| Status | Description |
|--------|-------------|
| **pending** | Summary queued for processing |
| **processing** | AI is generating the summary |
| **completed** | Summary ready (content populated) |
| **failed** | Generation failed (error_message explains why) |

### API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/conversations/{id}/summaries` | Create summary (async, returns 202) |
| `GET` | `/conversations/{id}/summaries` | List all summaries |
| `GET` | `/conversations/{id}/summaries/{summary}` | Get summary details |

### Summary Creation

- Specify optional `from_position` and `to_position` to summarize a range
- `CreateConversationSummaryJob` processes asynchronously
- Tracks `original_token_count`, `token_count`, compression ratio
- Stores which messages were summarized (`summarized_message_ids`)

### Integration with Context Filtering

The `summary_boundary` strategy:
- Finds the latest completed summary for the conversation
- Clips messages at the summary boundary
- Injects summary content as the first user message after system prompt
- Does NOT automatically create summaries (client must request via API)

**Config:** `config/ai.php` > `summarization`

---

## 9. Conversation Memory

Semantic search within conversation history using message embeddings.

**Toggle:** Requires `RAG_ENABLED=true` in `.env`

### Message Embeddings

- Stored in `message_embeddings` table with pgvector support
- Only user and assistant messages are embedded (not system/tool)
- Same embedding model as document chunks
- `EmbedConversationMessagesJob` processes messages asynchronously
- Content hash prevents duplicate embedding generation

### Memory API

| Method | Endpoint | Description |
|--------|----------|-------------|
| `POST` | `/conversations/{id}/memory/recall` | Semantic search in message history |
| `POST` | `/conversations/{id}/memory/embed` | Trigger embedding generation |
| `GET` | `/conversations/{id}/memory/status` | Check embedding progress |

### Recall Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `query` | string | What to search for (required) |
| `top_k` | integer | Max results (1-50, default 5) |
| `threshold` | number | Min similarity 0-1 (default 0.3) |
| `hybrid` | boolean | Use hybrid search (default false) |

### Agent Tools

Agents can use these tools (when RAG is enabled):

| Tool | Purpose |
|------|---------|
| `conversation_recall` | Search previous messages semantically |
| `conversation_memory_status` | Check how many messages are indexed |

**Source:** `app/Services/Tools/ConversationMemoryToolHandler.php`

---

## 10. Web Search & Fetch

### Web Search (SearXNG)
Privacy-respecting metasearch aggregating Google, Bing, DuckDuckGo.

- Default max results: 5
- Cache: Redis, 1-hour TTL
- Retry: 2 attempts, 500ms between
- Safe search: configurable (0=off, 1=moderate, 2=strict)
- Engines: configurable comma-separated list

**Config:** `config/search.php`

### Web Fetch (Content Extraction)
Fetch and extract main content from any URL.

- Timeout: 15 seconds
- Max page size: 5MB
- Max extracted text: 50,000 characters
- Removes scripts, styles, navigation automatically
- Allowed types: HTML, plain text, JSON, XML
- Cache: Redis, 30-minute TTL
- Retry: 2 attempts, 500ms between
- SSRF protection: optional private IP blocking

**Config:** `config/webfetch.php`

---

## 11. Frontend

Single-page application built with Vue 3 + Inertia.js v2 + Tailwind CSS v4.

### Pages (35 total)

| Area | Pages |
|------|-------|
| **Auth** | Login, Register, Password Reset, 2FA Challenge, Email Verify, Confirm Password |
| **Agents** | List, Create, Show, Edit |
| **Conversations** | List, Show (real-time chat) |
| **Tools** | List, Create, Show, Edit |
| **System Prompts** | List, Create, Show, Edit |
| **Documents** | List, Create, Show/Preview |
| **Files** | List, Show |
| **AI Backends** | List, Show (model management) |
| **Search** | Testing interface |
| **Settings** | Profile, Password, API Tokens, 2FA |
| **Other** | Dashboard, Welcome |

### Component Library
- 158 Vue components based on shadcn/vue (Radix Vue)
- Pre-built: Alert, Avatar, Badge, Button, Card, Dialog, Dropdown, Input, Progress, Select, Table, Tabs, etc.
- Custom: ConversationMessage, ToolRequestDialog, DocumentPicker, ThemeToggle, StreamingPhases
- Tool result renderers: WebSearchResult, WebFetchResult, FileReadResult, BashResult, TodoResult, DocumentResult

### Key Technologies
- TypeScript with Composition API (`<script setup>`)
- Vee-validate + Zod for form validation
- TanStack Vue Table for data tables
- Laravel Echo for WebSocket integration
- Markdown-it + DOMPurify for content rendering
- Lucide Vue icons
- Vue Sonner for toast notifications
- Dark mode support

---

## 12. REST API

35+ endpoints at `/api/v1/`, all authenticated via Sanctum tokens.

### Endpoints

**Auth:**
- `POST /auth/register` - Register new user
- `POST /auth/login` - Login, returns bearer token
- `POST /auth/logout` - Revoke token
- `GET /auth/user` - Get authenticated user

**Agents:** `GET|POST /agents`, `GET|PUT|DELETE /agents/{id}`, `POST /agents/{id}/tools`, `DELETE /agents/{id}/tools/{toolId}`

**Tools:** `GET|POST /tools`, `GET|PUT|DELETE /tools/{id}`

**System Prompts:** `GET|POST /system-prompts`, `GET|PUT|DELETE /system-prompts/{id}`

**Files:** `GET|POST /files`, `GET|DELETE /files/{id}`, `GET /files/{id}/download`

**Conversations:**
- `POST /agents/{id}/conversations` - Start conversation
- `GET /conversations` - List
- `GET /conversations/{id}` - Show
- `POST /conversations/{id}/messages` - Send message
- `GET /conversations/{id}/status` - Poll status
- `GET /conversations/{id}/stream` - SSE stream
- `POST /conversations/{id}/tool-results` - Submit tool result
- `POST /conversations/{id}/stop` - Cancel
- `DELETE /conversations/{id}` - Delete

**Conversation Summaries:**
- `GET /conversations/{id}/summaries` - List summaries
- `POST /conversations/{id}/summaries` - Create summary (async)
- `GET /conversations/{id}/summaries/{summary}` - Show summary

**Conversation Memory:**
- `POST /conversations/{id}/memory/recall` - Semantic search in history
- `POST /conversations/{id}/memory/embed` - Trigger embedding generation
- `GET /conversations/{id}/memory/status` - Check embedding progress

**Documents:**
- `GET /documents/supported-types` - List supported formats
- `GET|POST /documents`, `GET|DELETE /documents/{id}`
- `GET /documents/{id}/stages` - Processing stages
- `GET /documents/{id}/chunks` - List chunks
- `GET /documents/{id}/preview` - Preview
- `POST /documents/{id}/reprocess` - Re-run pipeline

**AI Backends:**
- `GET /ai-backends` - List configured backends
- `GET /ai-backends/{backend}/models` - List models
- `POST /ai-backends/{backend}/models/pull` - Download model
- `GET /ai-backends/{backend}/models/pull/{id}/stream` - Download progress
- `GET /ai-backends/{backend}/models/{model}` - Model details
- `DELETE /ai-backends/{backend}/models/{model}` - Delete model

### Auth & Rate Limiting
- Token-based: `Authorization: Bearer {token}` (24h expiration)
- Laravel Fortify: registration, password reset, 2FA
- Rate limiting: 60 requests/min per user, 5 login attempts/min per IP

---

## 13. Infrastructure

### Docker Services (compose.yaml)

| Service | Image | Port | Purpose |
|---------|-------|------|---------|
| **laravel.test** | sail-8.5/app | 80 | Application server |
| **laravel.worker** | sail-8.5/app | - | Queue worker (Horizon) |
| **laravel.vite** | sail-8.5/app | 5173 | Vite dev server |
| **pgsql** | pgvector/pgvector:pg16 | 5432 | Database + vector search |
| **pgadmin** | dpage/pgadmin4 | 8080 | Database admin UI |
| **redis** | redis:alpine | 6379 | Cache + queue broker |
| **rustfs** | rustfs:latest | 9000/9001 | S3-compatible object storage |
| **mailpit** | mailpit:latest | 1025/8025 | Email testing |
| **ollama** | ollama:latest | 11434 | Local LLM inference |
| **vllm-gpu** | custom (NVIDIA) | 8001 | GPU-accelerated inference |
| **vllm-cpu** | custom | 8002 | CPU inference fallback |
| **searxng** | searxng:latest | 8888 | Meta search engine |

### Background Jobs

| Job | Queue | Timeout | Purpose |
|-----|-------|---------|---------|
| `ProcessConversationTurn` | default | 330s | Main agentic loop |
| `ProcessDocumentJob` | default | 300s | Document ingestion pipeline |
| `EmbedDocumentChunksJob` | default | 600s | Generate embeddings for document chunks |
| `EmbedConversationMessagesJob` | default | 600s | Generate embeddings for conversation messages |
| `CreateConversationSummaryJob` | default | 300s | Generate conversation summaries |
| `PullModelJob` | default | 2h | Download AI models |
| `CleanupTempFilesJob` | low | 120s | Daily temp file cleanup |

### Queue Management
- Laravel Horizon with two pools:
  - **ai-workers**: high + default queues, 10 processes (prod) / 3 (local), 256MB memory
  - **low-priority**: low queue, 2 processes (prod) / 1 (local), 128MB memory

---

## 14. Security

| Layer | Protection |
|-------|-----------|
| **Command execution** | Dangerous pattern blocking (rm -rf, chmod 777, mkfs, dd, fork bombs) |
| **File access** | Denied paths (.env, storage/app/private, sessions), max 2000 lines read, max 10MB |
| **API tools** | SSRF protection (`TOOLS_BLOCK_PRIVATE_IPS`), blocked hosts list |
| **Web fetch** | SSRF protection (`WEBFETCH_BLOCK_PRIVATE_IPS`), content type whitelist |
| **Authentication** | Sanctum tokens (24h), Fortify 2FA, bcrypt (12 rounds) |
| **Rate limiting** | 60 req/min per user, 5 login attempts/min per IP |
| **Search** | Max 1000 results, excluded directories (node_modules, vendor, .git) |

**Config:** `config/agent.php` (paths, commands, file limits, API tool security)

---

## Summary Table

| Feature | Status | Config File | Key ENV vars |
|---------|--------|-------------|-------------|
| Ollama backend | Ready | `config/ai.php` | `OLLAMA_BASE_URL`, `OLLAMA_MODEL` |
| vLLM GPU backend | Ready | `config/ai.php` | `VLLM_GPU_BASE_URL`, `VLLM_GPU_MODEL` |
| vLLM CPU backend | Ready | `config/ai.php` | `VLLM_CPU_BASE_URL`, `VLLM_CPU_MODEL` |
| Claude backend | Ready | `config/ai.php` | `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL` |
| OpenAI backend | Ready | `config/ai.php` | `OPENAI_API_KEY`, `OPENAI_MODEL` |
| HuggingFace backend | Ready | `config/ai.php` | `HUGGINGFACE_API_KEY`, `HUGGINGFACE_MODEL` |
| Agent management | Ready | `config/agent.php` | `AGENT_MAX_TURNS` |
| Conversations | Ready | `config/agent.php` | `AGENT_MAX_TURNS` |
| SSE streaming | Ready | - | - |
| WebSocket (Reverb) | Ready | `config/reverb.php` | `REVERB_*` |
| System tools (15) | Ready | `config/agent.php` | `AGENT_COMMAND_TIMEOUT` |
| Custom API tools | Ready | `config/agent.php` | `TOOLS_BLOCK_PRIVATE_IPS` |
| Document ingestion | Ready | `config/document.php` | `CHUNK_MAX_TOKENS`, `DOCUMENT_MAX_SIZE` |
| RAG system | Ready | `config/ai.php` | `RAG_ENABLED`, `RAG_SEARCH_TYPE`, `RAG_TOP_K` |
| Context filtering | Ready | `config/ai.php` | - |
| Context filter pipeline | Ready | `config/ai.php` | - |
| Conversation summaries | Ready | `config/ai.php` | - |
| Conversation memory | Ready | `config/ai.php` | `RAG_ENABLED` |
| Web search | Ready | `config/search.php` | `SEARXNG_URL`, `SEARXNG_ENGINES` |
| Web fetch | Ready | `config/webfetch.php` | `WEBFETCH_TIMEOUT`, `WEBFETCH_MAX_SIZE` |
| Frontend (Vue 3) | Ready | - | - |
| REST API (35+ endpoints) | Ready | - | - |
| Horizon queues | Ready | `config/horizon.php` | `QUEUE_CONNECTION` |
| Auth (Sanctum + Fortify) | Ready | `config/sanctum.php` | `SANCTUM_STATEFUL_DOMAINS` |
