# Plan: Complete Document RAG + Rework Context Filtering + Conversation Memory

## Context

The RAG infrastructure is built but disconnected, and the context filtering system needs rework to be client-actionable.

**RAG gaps:**

1. `EmbedDocumentChunksJob` is never dispatched after document processing
2. `document_search` tool uses keyword-only search instead of `RAGPipeline`

**Context filtering issues:**

3. `ProcessConversationTurn` bypasses filtering (line 141 calls `getMessages()` directly)
4. Summarization is a side-effect of filtering (should be explicit via API)
5. Single strategy per agent (should be cumulative)
6. Old messages are lost (should be retrievable via embeddings)

## Architectural Decisions

- **Summaries** — Client-driven via API. Dispatches a job. Client chooses message range (`from_position`, `to_position`; both optional, defaults to first→last). `SummaryBoundaryStrategy` places summary as the first user message, then remaining messages follow.
- **Cumulative filters** — Pipeline pattern, ordered array per agent.
- **Message embeddings** — Client-driven via API route that dispatches a job. Separate from document RAG.
- **Memory recall** — Explicit only via `conversation_recall` tool. No automatic injection.
- **Status tracking** — Both summaries and message embeddings have a `status` field (pending/processing/completed/failed) since both are processed via jobs.
- **DB cleanup** — Delete `SummarizationStrategy` and all related code. Remove `is_synthetic`, `summarized`, `summary_id` columns from messages. Full removal, not deprecation.
- **Rollout** — Phased. Ship and test each phase independently.

---

## Phase 0: Complete Document RAG Pipeline

### 0.1 — Dispatch `EmbedDocumentChunksJob` after document processing

After `$document->markAs(DocumentStatus::Ready)` (line 82 in `ProcessDocumentJob`):

```php
if (config('ai.rag.enabled', true)) {
    EmbedDocumentChunksJob::dispatch($document);
}
```

**File:** `app/Jobs/ProcessDocumentJob.php`

### 0.2 — Fix `RetrievalService::buildBaseQuery()` for sparse search

`buildBaseQuery()` (line 240) applies `whereNotNull('embedding_generated_at')` to ALL queries. Sparse search doesn't need embeddings. Split: dense/hybrid keep the constraint, sparse removes it.

**File:** `app/Services/RAG/RetrievalService.php`

### 0.3 — Upgrade `document_search` to use RAG pipeline

Replace keyword-only search in `DocumentToolHandler::search()` (line 284) with `RAGPipeline::execute()`. Falls back to keyword search when RAG disabled or no embeddings.

If `document_id` arg is passed, filter to that single document. Otherwise search all attached documents.

**File:** `app/Services/Tools/DocumentToolHandler.php`

### 0.4 — Phase 0 Tests

- `ProcessDocumentJob` dispatches `EmbedDocumentChunksJob` when RAG enabled
- `ProcessDocumentJob` does NOT dispatch when RAG disabled
- `RetrievalService` sparse search works without embeddings
- `document_search` uses RAG pipeline when embeddings available
- `document_search` falls back to keyword search when no embeddings

---

## Phase 1: Fix Context Filtering Foundation

### 1.1 — Wire context filtering into ProcessConversationTurn

**The problem:** `ProcessConversationTurn::handle()` at line 142 calls `$this->conversation->getMessages()` directly, completely bypassing the context filtering system that's already built.

**The fix:** Add `ConversationService` as a dependency to `handle()` (Laravel auto-injects it) and use `getMessagesForAI()` instead.

```php
// app/Jobs/ProcessConversationTurn.php

// 1. Add import
use App\Services\ConversationService;

// 2. Add to handle() signature (line 83)
public function handle(
    AIBackendManager $aiBackendManager,
    ToolService $toolService,
    ConversationService $conversationService,  // ← ADD
): void {

// 3. Replace line 142
// BEFORE:
'messages' => $this->conversation->getMessages(),

// AFTER — estimate tool definition tokens from tool schemas:
$toolSchemas = $this->getAllToolSchemas();
$toolDefinitionTokens = (int) ceil(mb_strlen(json_encode($toolSchemas)) / 4);

$filteredMessages = $conversationService->getMessagesForAI(
    conversation: $this->conversation,
    maxOutputTokens: 4096,
    toolDefinitionTokens: $toolDefinitionTokens,
);

$context = [
    'messages' => $filteredMessages,
    'tools' => $toolSchemas,
    // ... rest unchanged
];
```

**Key details:**
- `getMessagesForAI()` already handles the threshold check (`isApproachingContextLimit`), strategy resolution, event dispatching, and logging
- No changes needed to `ConversationService` — it already returns `array<ChatMessage>` which is what the backends expect
- The filtering is transparent: below threshold it returns raw messages unchanged

**File:** `app/Jobs/ProcessConversationTurn.php` (only file modified)

### 1.2 — Phase 1 Tests

Create `tests/Feature/Jobs/ProcessConversationTurnFilteringTest.php`:

- `ProcessConversationTurn calls getMessagesForAI instead of getMessages` — Mock `ConversationService`, assert `getMessagesForAI()` is called with the conversation
- `context filtering is applied when approaching context limit` — Create conversation near context limit, verify filtered messages are used (fewer messages than raw)
- `context filtering is skipped below threshold` — Create short conversation, verify all messages returned unchanged
- `ContextFiltered event is dispatched when filtering occurs` — Use `Event::fake()`, trigger filtering, assert `ContextFiltered` event

Run with: `./vendor/bin/sail test --compact tests/Feature/Jobs/ProcessConversationTurnFilteringTest.php`

---

## Phase 2: Decouple Summaries from Filtering

### 2.1 — Migration: add `status` to `conversation_summaries`

Add `status` column (enum: `pending`, `processing`, `completed`, `failed`) + `error_message` (nullable text).

**File:** New migration

### 2.2 — Migration: remove deprecated columns from messages

Remove `is_synthetic`, `summarized`, `summary_id` columns and their indexes.

**File:** New migration

### 2.3 — `CreateConversationSummaryJob`

Background job that:

- Accepts `conversation_id`, `from_position` (optional), `to_position` (optional)
- If neither is provided, summarizes from first to last message
- Sets summary status to `processing`
- Calls `SummarizationService::summarize()` for messages in the given range
- Sets status to `completed` on success, `failed` on error
- Stores error message on failure

**File:** `app/Jobs/CreateConversationSummaryJob.php`

### 2.4 — Summary API routes

- `POST /api/v1/conversations/{conversation}/summaries` — Accepts `from_position` (optional int), `to_position` (optional int). Defaults to first→last when omitted. Creates summary record with `status: pending`, dispatches `CreateConversationSummaryJob`. Returns summary with status.
- `GET /api/v1/conversations/{conversation}/summaries` — List summaries (client polls for status)
- `GET /api/v1/conversations/{conversation}/summaries/{summary}` — Show one

**Files:**

- `app/Http/Controllers/Api/V1/ConversationSummaryController.php`
- `app/Http/Requests/StoreSummaryRequest.php` (validates `from_position` optional integer, `to_position` optional integer)
- `app/Http/Resources/ConversationSummaryResource.php`
- `routes/api.php`

### 2.5 — Create `SummaryBoundaryStrategy`

New filter strategy:

- Finds latest `completed` `ConversationSummary` for the conversation
- Returns: system prompt + summary content as **first user message** + all messages AFTER `to_position`
- No summary? Acts like NoOp
- Does NOT create summaries

**File:** `app/Services/ContextFilter/Strategies/SummaryBoundaryStrategy.php`

### 2.6 — Delete `SummarizationStrategy` and related code

Fully remove:

- `app/Services/ContextFilter/Strategies/SummarizationStrategy.php` — delete file
- All references to `SummarizationStrategy` in `ContextFilterManager` (strategy registration)
- Any `'summarization'` strategy name references in config, validation, tests
- All code that creates synthetic/summary messages during filtering

**Files:** Multiple (strategy file, manager, config, agent validation, existing tests)

### 2.7 — Phase 2 Tests

- Summary API: create summary dispatches job, returns pending status
- Summary API: list/show return summaries with status
- Summary API: both `from_position` and `to_position` are optional (defaults to full range)
- `CreateConversationSummaryJob`: sets status processing → completed
- `CreateConversationSummaryJob`: sets status → failed on error
- `SummaryBoundaryStrategy`: clips at latest completed summary
- `SummaryBoundaryStrategy`: places summary as first user message
- `SummaryBoundaryStrategy`: passes through when no summaries exist
- `SummaryBoundaryStrategy`: ignores pending/failed summaries
- Migration: deprecated columns removed
- `SummarizationStrategy` file no longer exists

---

## Phase 3: Cumulative Filter Pipeline

### 3.1 — Migration: add `context_strategies` to agents

Add `context_strategies` (JSON, nullable) alongside existing `context_strategy`. Migrate existing data: wrap string values in JSON arrays.

**File:** New migration + `app/Models/Agent.php`

### 3.2 — Update `ContextFilterManager::filterForConversation()`

Pipeline pattern:

```
strategies = agent.context_strategies ?? [agent.context_strategy] ?? ['token_budget']

for each strategy in pipeline:
    result = strategy.filter(context)
    context = rebuild with result.messages
    accumulate removedMessageIds
```

`FilterResult` gets `strategiesApplied` array.

**Files:**

- `app/Services/ContextFilter/ContextFilterManager.php`
- `app/DTOs/FilterResult.php`
- `app/Models/Agent.php` (accessor for strategies array)

### 3.3 — Update Agent validation

`booted()` in `Agent.php` validates each strategy in the array.

### 3.4 — Phase 3 Tests

- Single string strategy backward compat (treated as `[strategy]`)
- Pipeline: two strategies applied in order
- Pipeline: each strategy receives previous output
- `removedMessageIds` accumulated across strategies
- `strategiesApplied` lists all strategies used
- Agent validation: rejects invalid strategy names in array
- Agent validation: accepts valid array of strategies

---

## Phase 4: Conversation Memory (Message Embeddings)

### 4.1 — Migration: `message_embeddings` table

```sql
id, message_id (ULID FK), conversation_id (FK),
content, content_hash (SHA256),
embedding_raw (JSON), embedding (pgvector 1536),
embedding_model, embedding_dimensions, embedding_generated_at,
token_count, message_role, message_position,
status (pending/processing/completed/failed),
error_message (nullable text),
metadata (JSON), timestamps

Indexes: (conversation_id, message_position), content_hash, HNSW on embedding
```

### 4.2 — `MessageEmbedding` model + factory

**File:** `app/Models/MessageEmbedding.php`

### 4.3 — `EmbedConversationMessagesJob`

Background job that:

- Accepts `conversation_id` and array of `message_ids`
- Sets embedding status to `processing`
- Only embeds user + assistant messages (skips tool, system)
- Uses `EmbeddingService::embedBatch()` (reuses caching)
- Creates `MessageEmbedding` records with pgvector
- Sets status to `completed` on success, `failed` on error

Same patterns as `EmbedDocumentChunksJob` (retry, backoff, batch size).

**File:** `app/Jobs/EmbedConversationMessagesJob.php`

### 4.4 — Message Embedding API routes

- `POST /api/v1/conversations/{conversation}/memory/embed` — Accepts `message_ids` (required array). Creates embedding records with `status: pending`, dispatches `EmbedConversationMessagesJob`. Returns embeddings with status.
- `GET /api/v1/conversations/{conversation}/memory/stats` — Stats: embedded count, pending count, total tokens
- `POST /api/v1/conversations/{conversation}/memory/recall` — Accepts `query` (required), `top_k`, `threshold`. Searches embedded messages semantically. Returns results.

**Files:**

- `app/Http/Controllers/Api/V1/ConversationMemoryController.php`
- `app/Http/Requests/EmbedMessagesRequest.php` (validates `message_ids` required array)
- `app/Http/Requests/RecallMemoryRequest.php` (validates `query` required string)
- `routes/api.php`

### 4.5 — `ConversationMemoryService`

Decoupled from `RetrievalService`. Direct pgvector queries.

```php
class ConversationMemoryService
{
    public function __construct(private EmbeddingService $embeddingService) {}

    public function embedMessages(Conversation $conversation, array $messageIds): void;
    public function recall(Conversation $conversation, string $query, array $options = []): array;
    public function buildMemoryContext(array $results, int $maxTokens = 2000): string;
    public function getStats(Conversation $conversation): array;
}
```

**File:** `app/Services/ConversationMemoryService.php`

### 4.6 — `conversation_recall` system tool

Add to `ToolSchemaRegistry`: `conversation_recall` with params `query` (required), `top_k` (default 5). Agent can explicitly search embedded conversation history.

This is the **only** path to old context — no automatic injection.

**Files:**

- `app/Services/ToolSchemaRegistry.php`
- `app/Services/ToolHandlers/ConversationRecallToolHandler.php`
- `app/Jobs/ProcessConversationTurn.php` (route tool calls)

### 4.7 — Phase 4 Tests

- Embed API: dispatches job, returns pending status
- Embed API: validates `message_ids` required
- `EmbedConversationMessagesJob`: status pending → processing → completed
- `EmbedConversationMessagesJob`: skips tool/system messages
- `EmbedConversationMessagesJob`: status → failed on error
- Recall API: returns semantically relevant messages
- Stats API: returns correct counts
- `ConversationMemoryService::recall()`: pgvector cosine similarity works
- `conversation_recall` tool: returns formatted results
- `conversation_recall` tool: returns empty when no embeddings

---

## Phase 5: Configuration

Add to `config/ai.php`:

```php
'conversation_memory' => [
    'enabled' => env('CONVERSATION_MEMORY_ENABLED', true),
    'max_recall_tokens' => 2000,
    'recall_top_k' => 5,
    'recall_threshold' => 0.4,
    'embedding_model' => null, // null = use rag.embedding_model
],
```

---

## Implementation Order

| Step | What | Depends on |
| ---- | ---- | ---------- |
| 0.1 | Dispatch `EmbedDocumentChunksJob` in `ProcessDocumentJob` | Nothing |
| 0.2 | Fix `buildBaseQuery` for sparse search | Nothing |
| 0.3 | Upgrade `document_search` to use `RAGPipeline` | Nothing |
| 0.4 | Phase 0 tests | 0.1-0.3 |
| 1.1 | Wire context filtering into `ProcessConversationTurn` | Phase 0 |
| 1.2 | Phase 1 tests | 1.1 |
| 2.1 | Migration: `status` on `conversation_summaries` | Nothing |
| 2.2 | Migration: remove deprecated message columns | Nothing |
| 2.3 | `CreateConversationSummaryJob` | 2.1 |
| 2.4 | Summary API controller + routes | 2.3 |
| 2.5 | `SummaryBoundaryStrategy` | 2.1 |
| 2.7 | Phase 2 tests | 2.1-2.5 |
| 3.1 | Migration: `context_strategies` on agents | Nothing |
| 3.2 | Cumulative pipeline in `ContextFilterManager` | 3.1 |
| 3.4 | Phase 3 tests | 3.1-3.2 |
| 4.1 | Migration: `message_embeddings` table | Nothing |
| 4.2 | `MessageEmbedding` model + factory | 4.1 |
| 4.3 | `EmbedConversationMessagesJob` | 4.2 |
| 4.4 | Memory API routes | 4.3 |
| 4.5 | `ConversationMemoryService` | 4.2 |
| 4.6 | `conversation_recall` tool + handler | 4.5 |
| 4.7 | Phase 4 tests | 4.1-4.6 |
| 5 | Config updates | Nothing |

## Key Files Modified

| File | Changes |
| ---- | ------- |
| `app/Jobs/ProcessDocumentJob.php` | Dispatch `EmbedDocumentChunksJob` |
| `app/Jobs/ProcessConversationTurn.php` | Context filtering, `conversation_recall` tool routing |
| `app/Services/Tools/DocumentToolHandler.php` | Use `RAGPipeline` in `document_search` |
| `app/Services/RAG/RetrievalService.php` | Fix `buildBaseQuery` for sparse |
| `app/Services/ContextFilter/ContextFilterManager.php` | Cumulative pipeline |
| `app/Services/ConversationService.php` | Expose `FilterResult` |
| `app/Services/ToolSchemaRegistry.php` | Add `conversation_recall` tool |
| `app/DTOs/FilterResult.php` | Add `strategiesApplied` |
| `app/Models/Agent.php` | `context_strategies` accessor + validation |
| `app/Models/ConversationSummary.php` | Add `status` field |
| `config/ai.php` | Add `conversation_memory` section |
| `routes/api.php` | Summary + memory routes |

## Key Files Deleted

| File | Reason |
| ---- | ------ |
| `app/Services/ContextFilter/Strategies/SummarizationStrategy.php` | Replaced by client-driven summaries + `SummaryBoundaryStrategy` |

## Key Files Created

| File | Purpose |
| ---- | ------- |
| `app/Services/ContextFilter/Strategies/SummaryBoundaryStrategy.php` | Clips at latest summary |
| `app/Services/ConversationMemoryService.php` | Embed + recall old messages |
| `app/Services/ToolHandlers/ConversationRecallToolHandler.php` | `conversation_recall` tool handler |
| `app/Models/MessageEmbedding.php` | Embedding storage model |
| `app/Jobs/CreateConversationSummaryJob.php` | Background summarization |
| `app/Jobs/EmbedConversationMessagesJob.php` | Background embedding |
| `app/Http/Controllers/Api/V1/ConversationSummaryController.php` | Summary API |
| `app/Http/Controllers/Api/V1/ConversationMemoryController.php` | Memory API |

## Verification

1. **Phase 0:** Upload document → chunks get embeddings → agent `document_search` → semantic results
2. **Phase 1:** Long conversation → context filtering triggers → token budget respected
3. **Phase 2:** `POST /summaries` (optional `from_position`/`to_position`) → job runs → status becomes completed → `SummaryBoundaryStrategy` places summary as first user message → `SummarizationStrategy` deleted
4. **Phase 3:** `["summary_boundary", "token_budget"]` on agent → pipeline runs both in order
5. **Phase 4:** `POST /memory/embed` with message IDs → job runs → status completed → `conversation_recall` tool works
6. **Backward compat:** Single `context_strategy` string still works
7. `php artisan test --compact` after each phase
8. `vendor/bin/pint --dirty` for formatting
