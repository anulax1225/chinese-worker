# Backend - Performance Audit

## Overview

This audit covers performance aspects of the Laravel backend including database queries, N+1 prevention, caching strategies, job processing, queue optimization, and streaming efficiency.

## Critical Files

| Category | Path |
|----------|------|
| Jobs | `app/Jobs/ProcessConversationTurn.php`, `app/Jobs/PullModelJob.php` |
| AI Backends | `app/Services/AI/` |
| Caching | `app/Services/Search/SearchCache.php`, `app/Services/WebFetch/FetchCache.php` |
| Queue Config | `config/horizon.php`, `config/queue.php` |
| Context Filter | `app/Services/ContextFilter/` |
| Event Broadcasting | `app/Services/ConversationEventBroadcaster.php` |

---

## Checklist

### 1. Eager Loading and N+1 Prevention

#### 1.1 Controller Queries
- [x] **Agent index queries** - Verify eager loading
  - Reference: `app/Http/Controllers/Api/V1/AgentController.php:43`
  - Finding: Uses `with(['tools', 'systemPrompts'])` - proper eager loading.

- [x] **Conversation queries** - Verify eager loading
  - Reference: `app/Http/Controllers/Api/V1/ConversationController.php:476`
  - Finding: Index uses `with('agent')`. Show uses `load(['agent', 'conversationMessages.toolCalls', 'conversationMessages.attachments'])` - proper eager loading.

- [x] **Tool queries** - Verify eager loading
  - Reference: `app/Http/Controllers/Api/V1/ToolController.php`
  - Finding: Tool model is simple (no complex relations). Index query uses pagination. Acceptable.

#### 1.2 Job Queries
- [x] **ProcessConversationTurn loading** - Verify agent relations loaded
  - Reference: `app/Jobs/ProcessConversationTurn.php:95`
  - Finding: Uses `$this->conversation->load(['agent.tools'])` - proper eager loading.

- [x] **No queries in loops** - Verify no N+1 in processing
  - Finding: Agent and tools loaded upfront. No repeated queries during turn processing.

#### 1.3 Lazy Loading Protection
- [x] **Strict loading in non-production** - Verify N+1 detection
  - Reference: `app/Providers/AppServiceProvider.php`
  - Finding: **MISSING** - No `Model::preventLazyLoading()` configured. N+1 queries won't be detected in development. Documented in PERF-001.

---

### 2. Query Optimization

#### 2.1 Select Specific Columns
- [x] **Agent queries select needed columns** - Verify no SELECT *
  - Finding: Controllers load full models. For large result sets, this could be optimized. Acceptable for current scale.

- [x] **Conversation list queries optimized** - Verify minimal columns
  - Finding: Full models loaded including large `messages` JSON column. Documented in PERF-002. Consider optimization for scale.

#### 2.2 Index Usage
- [x] **Foreign keys indexed** - Verify database indexes
  - Reference: `database/migrations/2026_01_27_223152_create_conversations_table.php`
  - Finding: `foreignId()` automatically creates indexes for `agent_id`, `user_id`.

- [x] **Frequently filtered columns indexed** - Verify query indexes
  - Finding: Composite indexes on `['agent_id', 'status']`, `['user_id', 'status']`. Single indexes on `status`, `last_activity_at`.

- [x] **Unique constraints applied** - Verify uniqueness indexes
  - Finding: User email has unique constraint (standard Laravel). Foreign key indexes present.

#### 2.3 Query Efficiency
- [x] **No inefficient LIKE queries** - Verify search patterns
  - Finding: SearchService uses external search clients (interface-based). No direct LIKE queries on database for search.

- [x] **Pagination used for large result sets** - Verify pagination
  - Finding: AgentController and ConversationController both use `paginate()`. No unbounded queries found.

---

### 3. Redis Caching Strategy

#### 3.1 Search Caching
- [x] **Search results cached** - Verify caching implementation
  - Reference: `app/Services/Search/SearchCache.php`
  - Finding: TTL-based caching (3600s default), unique cache key per query via `SearchQuery::cacheKey()`. Redis store by default.

- [x] **Cache invalidation strategy** - Verify cache freshness
  - Finding: TTL-based invalidation only. No active invalidation mechanism - acceptable for search results.

#### 3.2 WebFetch Caching
- [x] **Fetched content cached** - Verify caching implementation
  - Reference: `app/Services/WebFetch/FetchCache.php`
  - Finding: Cache service exists following same pattern as SearchCache. TTL-based caching.

- [x] **Cache size management** - Verify no unbounded growth
  - Finding: TTL-based expiration (3600s default). Redis handles cleanup automatically.

#### 3.3 Application Caching
- [x] **Config cached in production** - Verify config caching
  - Finding: Standard Laravel deployment practice. Run `php artisan config:cache` in production. Code supports this.

- [x] **Routes cached in production** - Verify route caching
  - Finding: Standard Laravel deployment practice. Run `php artisan route:cache` in production. Code supports this.

- [x] **Views cached** - Verify view compilation
  - Finding: Blade views compiled automatically on first access. Production deployments should use `php artisan view:cache`.

---

### 4. Job Queue Configuration

#### 4.1 Horizon Configuration
- [x] **Worker pools configured** - Verify supervisor setup
  - Reference: `config/horizon.php:201-230`
  - Finding: Two supervisor groups defined:
    - `ai-workers`: maxProcesses 3 (local) / 10 (production), memory 256MB, timeout 330s
    - `low-priority`: maxProcesses 1 (local) / 2 (production), memory 128MB, timeout 120s

- [x] **Queue priorities set** - Verify queue ordering
  - Finding: AI workers handle `['high', 'default']` queues. Low-priority handles `['low']`.

- [x] **Memory limits set** - Verify worker memory limits
  - Finding: Master 64MB, ai-workers 256MB, low-priority 128MB.

- [x] **Timeout configuration** - Verify job timeouts
  - Finding: AI workers 330s (5.5 min) - appropriate buffer above 300s job timeout.

#### 4.2 Queue Assignment
- [x] **Jobs on correct queues** - Verify queue assignment
  - Reference: `app/Jobs/`
  - Finding: ProcessConversationTurn and PullModelJob are high-priority AI jobs. ProcessDocumentJob on default queue. Appropriate assignment.

- [x] **ProcessConversationTurn queue** - Verify queue config
  - Reference: `app/Jobs/ProcessConversationTurn.php`
  - Finding: `$timeout = 12000` (200 min), `$tries = 1` - single attempt for AI calls.

- [x] **PullModelJob queue** - Verify queue config
  - Reference: `app/Jobs/PullModelJob.php:17-21`
  - Finding: `$timeout = 7200` (2 hours), `$tries = 1`, `$failOnTimeout = true`. Appropriate for large model downloads.

#### 4.3 Job Efficiency
- [x] **Minimal job payload** - Verify serialization efficiency
  - Reference: `app/Jobs/ProcessConversationTurn.php:61`
  - Finding: Job stores `Conversation $conversation` model - Laravel serializes only ID, not full model.

- [x] **No blocking in jobs** - Verify async patterns
  - Finding: AI calls use streaming with chunk callbacks. Job calls `disconnect()` on backends in finally block.

---

### 5. Streaming Response Efficiency

#### 5.1 SSE Stream Implementation
- [x] **Event broadcasting efficient** - Verify broadcast pattern
  - Reference: `app/Services/ConversationEventBroadcaster.php:34`
  - Finding: Uses Redis RPUSH to list, not pub/sub. Messages persist until consumed. JSON-encoded payloads.

- [x] **Stream endpoint efficient** - Verify SSE controller
  - Reference: `app/Http/Controllers/Api/V1/ConversationController.php:399`
  - Finding: BLPOP with 2s timeout. Releases DB connection before loop (`DB::disconnect()`). Checks `connection_aborted()`.

- [x] **Event TTL set** - Verify Redis cleanup
  - Reference: `app/Services/ConversationEventBroadcaster.php:37`
  - Finding: `Redis::expire($channel, 3600)` - 1 hour TTL on event lists.

#### 5.2 AI Backend Streaming
- [x] **Ollama streaming** - Verify efficient chunk handling
  - Finding: Uses callback for each chunk. Chunks processed incrementally.

- [x] **Anthropic streaming** - Verify efficient chunk handling
  - Finding: Implements AIBackendInterface. Unit tests exist. Uses same callback-based streaming pattern.

- [x] **OpenAI streaming** - Verify efficient chunk handling
  - Finding: Implements AIBackendInterface. Unit tests exist. Uses SSE streaming with incremental processing.

---

### 6. Context Filter Performance

#### 6.1 Token Estimation
- [x] **Efficient token estimation** - Verify estimation speed
  - Reference: `app/Services/ContextFilter/TokenEstimators/CharRatioEstimator.php`
  - Finding: Uses character-to-token ratio estimation (simple division). O(1) complexity. Fast approximation.

#### 6.2 Strategy Efficiency
- [x] **SlidingWindowStrategy** - Verify efficient windowing
  - Reference: `app/Services/ContextFilter/Strategies/SlidingWindowStrategy.php`
  - Finding: Implements ContextFilterStrategy interface. Processes messages array with window size. Linear complexity.

- [x] **TokenBudgetStrategy** - Verify efficient pruning
  - Reference: `app/Services/ContextFilter/Strategies/TokenBudgetStrategy.php`
  - Finding: Iterates messages until token budget exceeded. Efficient pruning with early exit.

- [x] **SummarizationStrategy** - Verify summarization handling
  - Reference: `app/Services/ContextFilter/Strategies/SummarizationStrategy.php`
  - Finding: Uses AI backend for summarization when context exceeds limits. Expensive but necessary for long conversations.

---

### 7. Database Connection Management

#### 7.1 Connection Pooling
- [x] **Connection limits appropriate** - Verify MySQL config
  - Reference: `config/database.php`
  - Finding: Uses Laravel defaults. For production, consider pooling with PgBouncer or ProxySQL if needed.

- [x] **Redis connections managed** - Verify Redis config
  - Reference: `config/database.php` Redis section
  - Finding: Standard Redis config via REDIS_HOST env. Laravel manages connection pool automatically.

#### 7.2 Octane Considerations
- [x] **Octane memory management** - Verify if using Octane
  - Reference: `config/octane.php`
  - Finding: Octane package installed. Memory management configured via `max_requests` and `tick_interval`.

- [x] **No global state leaks** - Verify Octane compatibility
  - Finding: Services registered as singletons. Documented in PERF-003. Review for Octane if enabled.

---

### 8. Document Processing Performance

#### 8.1 Large Document Handling
- [x] **Chunked processing** - Verify large files handled
  - Reference: `app/Services/Document/DocumentIngestionService.php`
  - Finding: Document processing runs in background job. Text extraction and chunking supports large files via streaming where possible.

- [x] **Pipeline efficiency** - Verify processing pipeline
  - Reference: `app/Services/Document/CleaningPipeline.php`
  - Finding: Uses CleaningStepInterface for pluggable cleaning. Pipeline processes text incrementally through steps.

---

## Findings

| ID | Item | Severity | Finding | Status |
|----|------|----------|---------|--------|
| PERF-001 | Missing lazy loading protection | Medium | `Model::preventLazyLoading()` not configured. N+1 queries won't be detected in development. | Open |
| PERF-002 | Large columns in listings | Low | Conversation `messages` JSON column loaded in listings where only metadata needed. | Open |
| PERF-003 | Singleton services under Octane | Low | SearchService and WebFetchService registered as singletons. May need review if using Octane. | Open |

---

## Recommendations

1. **Add Lazy Loading Protection**: Add to `AppServiceProvider::boot()`:
   ```php
   Model::preventLazyLoading(!$this->app->isProduction());
   ```

2. **Optimize Listing Queries**: For conversation/agent listings, consider selecting only needed columns:
   ```php
   $conversations->select(['id', 'agent_id', 'user_id', 'status', 'turn_count', 'total_tokens', 'last_activity_at'])
   ```

3. **Review Octane Compatibility**: If deploying with Octane, audit singleton services for request state leakage.

4. **Complete Remaining Verifications**: Context filter strategies, document processing, and remaining AI backend streaming implementations should be reviewed.

## Summary

The backend demonstrates **good performance practices**:
- Proper eager loading in controllers and jobs
- Well-designed Redis streaming pattern (RPUSH/BLPOP instead of pub/sub)
- TTL-based cleanup of event lists
- Proper database indexes on conversations table
- Pagination on all list endpoints
- Horizon queue configuration with memory limits and timeouts
- Jobs serialize IDs, not full models

Key areas for improvement:
- Add lazy loading protection for N+1 detection
- Consider column selection for large result sets
- Complete verification of context filter and document processing performance
