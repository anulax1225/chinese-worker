# 60-Second Queue Delay Bug

## Summary

Jobs dispatched to the database queue experience a ~60-second delay before starting, but **only when an SSE client is connected**. Polling mode works without delay.

## Symptoms

1. User sends message to conversation
2. `ProcessConversationTurn` job dispatched
3. Job processes, AI responds with tool call
4. Job broadcasts `tool_request` event via Redis pub/sub
5. SSE client receives event, user approves tool
6. `submitToolResult` endpoint dispatches next job
7. **BUG: Next job doesn't start for ~60 seconds**
8. After delay, job runs normally

## Key Observation

The delay **only occurs when SSE is active**. With `--polling` flag, jobs start immediately.

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         CLI Client                               │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │ Send Message │───▶│  SSE Stream  │◀───│ Tool Prompt  │      │
│  └──────────────┘    └──────────────┘    └──────────────┘      │
└─────────────────────────────────────────────────────────────────┘
         │                    ▲                    │
         ▼                    │                    ▼
┌─────────────────────────────────────────────────────────────────┐
│                      Laravel Backend                             │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐      │
│  │ POST /message│    │ GET /stream  │    │POST /tool-   │      │
│  │              │    │ (SSE)        │    │    results   │      │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘      │
│         │                   │                    │               │
│         ▼                   ▼                    ▼               │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │                  ConversationService                      │  │
│  │  - Dispatches ProcessConversationTurn jobs               │  │
│  │  - Broadcasts events via ConversationEventBroadcaster    │  │
│  └──────────────────────────────────────────────────────────┘  │
│         │                   │                                    │
│         ▼                   ▼                                    │
│  ┌─────────────┐    ┌─────────────┐                            │
│  │ Database    │    │ Redis       │                            │
│  │ Queue (jobs)│    │ Pub/Sub     │                            │
│  └─────────────┘    └─────────────┘                            │
│         │                   ▲                                    │
│         ▼                   │                                    │
│  ┌─────────────────────────────────────────────────────────┐   │
│  │              Queue Worker Process                        │   │
│  │  - Polls database for jobs                              │   │
│  │  - Executes ProcessConversationTurn                     │   │
│  │  - Publishes events to Redis                            │   │
│  └─────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

## Root Cause Hypotheses

### 1. SSE Holds Database Connection (Investigated)

**Theory:** The SSE endpoint's blocking `$redis->subscribe()` holds a database connection, causing connection pool exhaustion.

**Fix Applied:**
```php
// ConversationController.php stream()
DB::disconnect(); // Release DB before blocking subscribe
$redis = Redis::connection('pubsub'); // Use dedicated connection
$redis->subscribe([$channel], ...);
```

**Result:** Did not fix the issue.

### 2. Redis Connection Interference (Investigated)

**Theory:** SSE subscribe and broadcaster publish share the same Redis connection, causing interference.

**Fix Applied:**
```php
// config/database.php - Added dedicated pubsub connection
'pubsub' => [
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_DB', '0'),
    'read_timeout' => -1, // Infinite for blocking subscribe
],
```

**Result:** Did not fix the issue.

### 3. Job Not Committed to Database (Investigated)

**Theory:** Jobs dispatched inside transactions aren't visible until commit.

**Fix Applied:**
```php
// config/queue.php
'database' => [
    'after_commit' => true, // Wait for transaction commit
],
```

**Result:** Did not fix the issue.

### 4. HTTP Keep-Alive Holding Resources (Investigated)

**Theory:** Guzzle HTTP client to Ollama holds connections open.

**Fix Applied:**
```php
// OllamaBackend.php
public function disconnect(): void {
    unset($this->client);
    $this->client = new Client([
        'headers' => ['Connection' => 'close'],
    ]);
}
```

**Result:** Did not fix the issue.

### 5. Job Stays Reserved After Exception (Investigated)

**Theory:** If cleanup code throws, job stays reserved for `retry_after` seconds (90s).

**Fix Applied:**
```php
// ProcessConversationTurn.php
} finally {
    try { $backend->disconnect(); } catch (\Throwable $e) { /* log */ }
    try { $broadcaster->disconnect(); } catch (\Throwable $e) { /* log */ }
    DB::disconnect();
    gc_collect_cycles();
}
```

**Result:** Did not fix the issue.

## Current Investigation

Added comprehensive logging to trace the exact timing:

### Log Points Added

| Component | Log Prefix | Events |
|-----------|------------|--------|
| ProcessConversationTurn | `[JOB]` | STARTED, AI call, tool broadcast, ENDING, FINISHED |
| ConversationController | `[SSE]` | Stream start, state checks, Redis subscribe, message received |
| ConversationService | `[ConversationService]` | processMessage, submitToolResult, job dispatch |
| ConversationEventBroadcaster | `[Broadcaster]` | Redis publish events |

### Expected Log Flow (Normal)

```
[ConversationService] submitToolResult called                    T+0ms
[ConversationService] Dispatching next ProcessConversationTurn   T+5ms
[ConversationService] submitToolResult completed                 T+10ms
[JOB] ProcessConversationTurn STARTED                           T+50ms  <-- Should be fast
```

### Actual Log Flow (Bug)

```
[ConversationService] submitToolResult called                    T+0ms
[ConversationService] Dispatching next ProcessConversationTurn   T+5ms
[ConversationService] submitToolResult completed                 T+10ms
[JOB] ProcessConversationTurn STARTED                           T+60000ms  <-- 60s delay!
```

## Files Modified

| File | Changes |
|------|---------|
| `app/Jobs/ProcessConversationTurn.php` | Added logging, DB::disconnect, gc_collect_cycles |
| `app/Http/Controllers/Api/V1/ConversationController.php` | Added logging, DB::disconnect before subscribe, dedicated pubsub connection |
| `app/Services/ConversationService.php` | Added logging at dispatch points |
| `app/Services/ConversationEventBroadcaster.php` | Added logging, no-op disconnect |
| `app/Services/AI/OllamaBackend.php` | Added disconnect(), Connection: close header |
| `app/Contracts/AIBackendInterface.php` | Added disconnect() method |
| `config/queue.php` | Set after_commit => true |
| `config/database.php` | Added dedicated pubsub Redis connection |
| `cli/chinese_worker/api/sse_client.py` | Added close() method |
| `cli/chinese_worker/cli.py` | Added try/finally with sse_client.close() |

## Remaining Hypotheses to Investigate

1. **Database Queue Polling Interval** - The queue worker may have a long sleep interval between polls
2. **PHP-FPM Process Blocking** - SSE may block the PHP-FPM worker that would handle the next request
3. **MySQL Connection Timeout** - The 60s matches MySQL's default `wait_timeout` for idle connections
4. **Laravel Queue Worker Sleep** - Default `--sleep=3` may interact with database connection state

## To Reproduce

1. Start services: `./vendor/bin/sail up -d`
2. Start queue worker: `./vendor/bin/sail artisan queue:work database --memory=256`
3. Start CLI with SSE: `cw chat 1` (no --polling flag)
4. Send message that triggers tool call (e.g., "list files in /tmp")
5. Approve the tool execution
6. Observe 60-second delay before next job starts

## Workaround

Use polling mode instead of SSE:
```bash
cw chat 1 --polling
```
