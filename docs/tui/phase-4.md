# Phase 4 — Summaries & Conversation Memory

> **Goal**: Trigger conversation summaries, view them, search conversation memory semantically, and see context usage information. These are the "intelligence" features that make long-running conversations actually work.

## What You Get at the End

- Trigger a summary of the current conversation (or a range) from the TUI
- View summaries with metadata (compression ratio, token counts)
- See when summary_boundary context strategy kicks in
- Search conversation memory semantically (recall)
- View memory/embedding status (how many messages indexed)
- Context usage indicator in the status bar (% of context window used)
- Automatic summary suggestion when context usage is high

## Prerequisites

- Phase 1 & 2 complete
- **New API client methods required** (see below)

## New API Client Methods

These endpoints exist on the backend but are **not yet in `client.py`**. Must be added:

```python
# ==================== Conversation Summaries ====================

def list_summaries(self, conversation_id: int) -> List[Dict[str, Any]]:
    """GET /conversations/{id}/summaries"""

def create_summary(
    self,
    conversation_id: int,
    from_position: Optional[int] = None,
    to_position: Optional[int] = None,
) -> Dict[str, Any]:
    """POST /conversations/{id}/summaries (returns 202)"""

def get_summary(self, conversation_id: int, summary_id: int) -> Dict[str, Any]:
    """GET /conversations/{id}/summaries/{summary}"""

# ==================== Conversation Memory ====================

def memory_recall(
    self,
    conversation_id: int,
    query: str,
    top_k: int = 5,
    threshold: float = 0.3,
    hybrid: bool = False,
) -> Dict[str, Any]:
    """POST /conversations/{id}/memory/recall"""

def memory_embed(self, conversation_id: int) -> Dict[str, Any]:
    """POST /conversations/{id}/memory/embed"""

def memory_status(self, conversation_id: int) -> Dict[str, Any]:
    """GET /conversations/{id}/memory/status"""
```

## Features

### 1. Conversation Summaries

#### Triggering a Summary

From chat, via slash command or keyboard shortcut:

```
You: /summarize
⏳ Creating summary of conversation #142...
✓ Summary created: 45 messages → 320 tokens (87% compression)

──── Summary ──────────────────────────────────────
The conversation focused on building a CSV parser
in Python. Key decisions: use pandas for large files,
implement streaming for memory efficiency. The user
requested error handling for malformed rows. A working
implementation was created and tested with sample data.
────────────────────────────────────────────────────
```

#### Viewing Summaries

```
You: /summaries
┌──────────────────────────────────────────────────┐
│ Summaries for conversation #142                  │
├──────────────────────────────────────────────────┤
│ #1  Messages 1-25   320 tokens (87% compressed)  │
│     Created: 2h ago  Status: ✓ completed         │
├──────────────────────────────────────────────────┤
│ #2  Messages 25-45  280 tokens (82% compressed)  │
│     Created: 30m ago  Status: ✓ completed        │
└──────────────────────────────────────────────────┘
```

#### Automatic Summary Suggestion

When the status bar shows high context usage (>75%), display a subtle notification:

```
┌──────────────────────────────────────────────────┐
│ CodeAssistant (claude)  ● Connected  CTX: 78% ⚠ │
│  💡 Context usage high. Run /summarize to free   │
│     context space.                               │
├──────────────────────────────────────────────────┤
```

This helps the user understand when summaries are useful and ties the UX to the backend's `summary_boundary` context strategy.

### 2. Conversation Memory (Semantic Recall)

#### Recall Command

Search past messages in the current conversation by meaning, not just text:

```
You: /recall "authentication implementation"
🔍 Searching conversation memory...

── Recall Results (3 matches) ────────────────────
  0.91  [msg #12, you]  "Can you implement JWT 
        authentication with refresh tokens?"
  0.85  [msg #15, assistant]  "Here's the auth 
        middleware implementation using..."
  0.79  [msg #8, you]  "The auth system needs to 
        support both API keys and OAuth..."
───────────────────────────────────────────────────
```

This is particularly useful in long conversations where you need to find what was discussed earlier.

#### Memory Status

```
You: /memory-status
📊 Conversation Memory (#142)
   Messages: 45 total
   Embedded: 38 (user + assistant only)
   Pending:  2 (processing...)
   Coverage: 95%
```

#### Trigger Embedding

If messages aren't indexed yet (e.g., after a long conversation):

```
You: /embed
⏳ Triggering embedding for unindexed messages...
✓ 7 messages queued for embedding
```

### 3. Context Usage Indicator

A persistent indicator in the status bar showing how much of the model's context window is being used.

```
│ CodeAssistant (claude-sonnet-4-5)  ● Connected  CTX: 42% │
```

Color coding:
- Green (0-60%): Plenty of room
- Yellow (60-80%): Getting full
- Red (80-100%): Near limit, summaries recommended

The context usage comes from the SSE `stats` data or can be computed client-side from message token estimates.

## Widgets

### `SummaryView` (extends `Static`)

Displays a single summary with metadata.

```python
class SummaryView(Static):
    """Formatted summary display."""
    
    def __init__(self, summary: Dict[str, Any]):
        self.summary = summary
        super().__init__()
    
    def render(self) -> str:
        s = self.summary
        compression = (1 - s["token_count"] / s["original_token_count"]) * 100
        header = f"Summary #{s['id']}  {s['token_count']} tokens ({compression:.0f}% compressed)"
        return f"[bold]{header}[/bold]\n\n{s['content']}"
```

### `RecallResults` (extends `Static`)

Displays semantic search results.

```python
class RecallResults(Static):
    """Formatted recall results."""
    
    def render_result(self, result: Dict) -> str:
        score = result["similarity"]
        role = result["role"]
        content = result["content"][:100]
        return f"  {score:.2f}  [{role}]  {content}..."
```

### `ContextIndicator` (extends `Static`)

Small widget in the status bar.

```python
class ContextIndicator(Static):
    """Context window usage indicator."""
    usage: reactive[float] = reactive(0.0)
    
    def render(self) -> str:
        pct = self.usage * 100
        color = "green" if pct < 60 else "yellow" if pct < 80 else "red"
        return f"CTX: [{color}]{pct:.0f}%[/{color}]"
```

## Slash Commands

| Command | Action |
|---------|--------|
| `/summarize` | Create summary of entire conversation |
| `/summarize <from> <to>` | Create summary of message range |
| `/summaries` | List summaries for current conversation |
| `/recall "<query>"` | Semantic search in conversation history |
| `/memory-status` | Show embedding/indexing status |
| `/embed` | Trigger embedding for unindexed messages |
| `/context` | Show detailed context usage breakdown |

## Changes to Existing Code

### StatusBar Enhancement

Add `ContextIndicator` widget to the status bar (from Phase 1). Update it from SSE stats events.

### ChatScreen

- Handle summary-related slash commands
- Handle memory-related slash commands  
- Show context usage warning notification when threshold exceeded
- Update context indicator from SSE stats

### Stream Handler

Extract context usage from SSE `completed` and `stats` events:

```python
elif event_type == "completed":
    stats = data.get("stats", {})
    context_usage = stats.get("context_usage", 0)
    self.post_message(ContextUsageUpdate(context_usage))
```

## Implementation Order

### Step 1: API client methods (Day 1)
- Add all 6 new methods to `client.py`
- Test with backend manually

### Step 2: Summary commands (Day 1-2)
- `/summarize` — create summary, poll for completion, display
- `/summaries` — list and display summaries
- Summary creation progress (pending → processing → completed)

### Step 3: Memory commands (Day 2-3)
- `/recall` — semantic search, display results
- `/memory-status` — show embedding progress
- `/embed` — trigger embedding

### Step 4: Context indicator (Day 3-4)
- Add to status bar
- Wire up from SSE stats
- Warning notification at high usage
- Color coding

### Step 5: Polish (Day 4)
- Automatic summary suggestion UX
- Error handling for missing RAG (graceful degradation if RAG not enabled)
- Help text explaining what these features do

## Backend Considerations

**No backend changes required.** All endpoints exist:
- `POST /conversations/{id}/summaries` (async, returns 202)
- `GET /conversations/{id}/summaries`
- `POST /conversations/{id}/memory/recall`
- `POST /conversations/{id}/memory/embed`
- `GET /conversations/{id}/memory/status`

**Note**: If RAG is not enabled on the backend (`RAG_ENABLED=false`), the memory endpoints will return errors. The TUI should handle this gracefully — show a message like "Memory features require RAG to be enabled on the server" and hide memory-related commands.

## Acceptance Criteria

- [ ] New API client methods for summaries and memory work
- [ ] `/summarize` creates a summary, shows progress, displays result
- [ ] `/summaries` lists conversation summaries
- [ ] `/recall` searches conversation memory and shows ranked results
- [ ] `/memory-status` shows embedding progress
- [ ] `/embed` triggers embedding for unindexed messages
- [ ] Context usage indicator shows in status bar
- [ ] Context indicator color-codes by usage level
- [ ] High context usage shows suggestion to summarize
- [ ] Graceful degradation when RAG is not enabled