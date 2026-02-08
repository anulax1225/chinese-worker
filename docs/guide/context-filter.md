# Context Filter

Automatic context management that prevents AI conversations from exceeding model context limits. Uses pluggable strategies to intelligently filter messages while preserving critical context.

## Overview

AI models have finite context windows. Long conversations can exceed these limits, causing errors or degraded responses. The Context Filter system automatically manages message history by:

- **View-only filtering** - Original messages stay in the database; filtering only affects what the AI sees
- **Pluggable strategies** - Switch between filtering approaches via configuration
- **Per-agent configuration** - Each agent can use different strategies and thresholds
- **Fail-open policy** - On errors, all messages are sent (with logging) rather than blocking

```
┌─────────────────────────────────────────────────────────────────┐
│                     Conversation Messages                        │
│   [System] [User] [Assistant] [Tool] [User] [Assistant] ...    │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                    Context Filter Manager                        │
│         Checks threshold → Resolves strategy → Filters          │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                     Filtered Messages                            │
│   [System] [User] [Assistant] [Tool] [Assistant]                │
│            ↑ Preserved: system, recent, tool chains             │
└─────────────────────────────────────────────────────────────────┘
                                │
                                ▼
┌─────────────────────────────────────────────────────────────────┐
│                        AI Backend                                │
│              Receives optimized context window                   │
└─────────────────────────────────────────────────────────────────┘
```

## How It Works

1. **Threshold Check** - Filtering triggers when context usage exceeds the configured threshold (default: 80%)
2. **Strategy Resolution** - The configured strategy is loaded from the agent or global defaults
3. **Token Estimation** - Messages are analyzed with content-aware token estimation
4. **Filtering** - Older messages are removed while preserving critical ones
5. **Observability** - Events are emitted for monitoring and debugging

### Preservation Rules

These messages are **never** filtered out:

| Message Type | Reason |
|--------------|--------|
| System prompt | Required for agent behavior |
| Pinned messages | Explicitly marked as important |
| Tool call chains | Both call and result must stay together |

## Strategies

### Token Budget (Default)

Fits messages within a calculated token budget. Uses content-aware estimation for accurate token counts.

```php
// Agent configuration
'context_strategy' => 'token_budget',
'context_options' => [
    'budget_percentage' => 0.8,  // Use 80% of available context
    'reserve_tokens' => 1000,    // Reserve extra tokens
],
```

**How it works:**
1. Calculates available budget: `context_limit - max_output_tokens - tool_definition_tokens`
2. Applies budget percentage
3. Keeps most recent messages that fit within budget
4. Always preserves system prompt and tool call chains

### Sliding Window

Keeps the N most recent messages. Simpler approach when token estimation isn't needed.

```php
// Agent configuration
'context_strategy' => 'sliding_window',
'context_options' => [
    'window_size' => 50,  // Keep last 50 messages (including system)
],
```

**Best for:**
- Conversations with consistent message sizes
- When simplicity is preferred over precision
- Fallback when token estimation is unavailable

### NoOp

Pass-through strategy that performs no filtering. All messages are sent to the AI.

```php
// Agent configuration
'context_strategy' => 'noop',
'context_options' => [],
```

**Use cases:**
- Debugging context issues
- Short conversations that won't exceed limits
- Testing without filtering interference

## Configuration

### Per-Agent Settings

Configure context filtering on individual agents:

| Field | Type | Default | Description |
|-------|------|---------|-------------|
| `context_strategy` | string | `token_budget` | Strategy name |
| `context_options` | json | `{}` | Strategy-specific options |
| `context_threshold` | float | `0.8` | Trigger threshold (0.0-1.0) |

Example agent configuration:

```php
Agent::create([
    'name' => 'Long Conversation Agent',
    'context_strategy' => 'token_budget',
    'context_options' => [
        'budget_percentage' => 0.75,
        'reserve_tokens' => 2000,
    ],
    'context_threshold' => 0.7,  // Trigger earlier at 70%
]);
```

### Global Defaults

Set defaults in `config/ai.php`:

```php
'context_filter' => [
    'default_strategy' => 'token_budget',
    'default_options' => ['budget_percentage' => 0.8],
    'default_threshold' => 0.8,
],
```

### Token Estimation Settings

Configure content-aware token estimation in `config/ai.php`:

```php
'token_estimation' => [
    'default_chars_per_token' => 4.0,   // English prose
    'json_chars_per_token' => 2.5,      // JSON/structured data
    'code_chars_per_token' => 3.0,      // Code with special chars
    'safety_margin' => 0.9,             // 10% safety buffer
    'cache_on_message' => true,         // Cache token counts
],
```

| Content Type | Chars/Token | Detection |
|--------------|-------------|-----------|
| English prose | 4.0 | Default for text |
| Code | 3.0 | Contains `function`, `class`, `->`, etc. |
| JSON/XML | 2.5 | Starts with `{` or `[` |

## Trigger Modes

### Automatic (Default)

Filtering triggers when context usage exceeds the threshold:

```
context_usage / context_limit >= context_threshold
```

### Manual Trigger

Force filtering regardless of threshold:

```php
$service->getMessagesForAI($conversation, forceFilter: true);
```

### Skip Trigger

Bypass filtering for debugging:

```php
$service->getMessagesForAI($conversation, skipFilter: true);
```

## Artisan Commands

### Test Filtering

Test context filtering on a specific conversation:

```bash
./vendor/bin/sail artisan context:test {conversation_id}

# With specific strategy
./vendor/bin/sail artisan context:test {conversation_id} --strategy=sliding_window

# With custom options
./vendor/bin/sail artisan context:test {conversation_id} --strategy=token_budget --options='{"budget_percentage":0.5}'
```

Output includes:
- Original message count
- Filtered message count
- Removed message IDs
- Token usage before/after
- Execution duration

## Observability

### Events

The system emits events for monitoring:

**ContextFiltered** - After every filtering operation:
```php
ContextFiltered::class
// Properties:
// - conversationId
// - strategyUsed
// - originalCount
// - filteredCount
// - removedMessageIds
// - contextUsageBefore
// - contextUsageAfter
// - durationMs
```

**ContextFilterResolutionFailed** - When strategy not found:
```php
ContextFilterResolutionFailed::class
// Properties:
// - strategyName
// - agentId (optional)
// - conversationId (optional)
```

### Logging

All filtering operations are logged:

```
[info] [ContextFilter] Filtered conversation 123: 50 → 25 messages using token_budget in 12.5ms
```

Errors are logged at `error` level with full context.

## Troubleshooting

### Strategy Not Found

**Symptom:** Log shows "Unknown context filter strategy: {name}. Falling back to NoOp."

**Solution:** Check the strategy name in agent configuration. Valid strategies:
- `token_budget`
- `sliding_window`
- `noop`

### Pinned Messages Exceed Limit

**Symptom:** Warning logged, only pinned messages sent to AI.

**Solution:** Reduce pinned messages or increase context limit. Maximum 10 pinned messages per conversation.

### Invalid Strategy Options

**Symptom:** Exception thrown when saving agent.

**Solution:** Check option types and ranges:
- `budget_percentage`: number between 0 (exclusive) and 1 (inclusive)
- `reserve_tokens`: non-negative integer
- `window_size`: positive integer

### Context Still Overflowing

**Symptom:** AI returns context limit errors despite filtering.

**Possible causes:**
1. Threshold too high - lower `context_threshold` to trigger earlier
2. Budget percentage too high - reduce `budget_percentage`
3. Large system prompt - consider shortening
4. Many pinned messages - review what's pinned

## Next Steps

- [AI Backends](ai-backends.md) - Configure the AI backends that receive filtered context
- [Configuration](configuration.md) - Full configuration reference
- [Queues & Jobs](queues-and-jobs.md) - Background processing for conversations
