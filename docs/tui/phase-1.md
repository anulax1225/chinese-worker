# Phase 1 — Foundation & Core Chat

> **Goal**: A working TUI where you can log in, pick an agent, and have a streaming agentic conversation with full tool support. This replaces both the broken Click CLI chat loop and the incomplete TUI prototype.

## What You Get at the End

- Launch `cw` → see login screen (or skip if token exists)
- Pick an agent from a list → enter chat
- Type messages → see streaming markdown responses in real-time
- Agent calls tools → approval modal → local execution → loop continues
- Thinking/reasoning blocks displayed separately
- Server-side tool execution (web_search, todos, etc.) shown as status indicators
- Ctrl+C stops generation, Escape goes back, Ctrl+Q quits
- SSE streaming with automatic polling fallback

## Architecture Decisions

### App Shell

```python
class CWApp(App):
    CSS_PATH = ["styles/theme.tcss", "styles/chat.tcss", "styles/home.tcss"]
    BINDINGS = [
        Binding("ctrl+q", "quit", "Quit"),
        Binding("ctrl+p", "command_palette", "Commands", show=False),  # placeholder for Phase 6
    ]
```

The app manages:
- `APIClient` instance (shared across screens)
- Authentication state
- Tool registry (platform-detected at startup)
- Global settings (auto-approve, etc.)

### Screen Flow (Phase 1)

```
LoginScreen ──(auth success)──→ HomeScreen ──(select agent)──→ ChatScreen
                                     ↑                              │
                                     └──────(Escape / back)─────────┘
```

### Streaming Architecture

The critical path — this must feel instant and smooth:

```
[SSE Thread]                    [Textual Main Loop]              [Screen]
    │                                │                               │
    │ SSE event ──→ Queue.put() ─────│                               │
    │                                │ worker polls queue ───────────→│
    │                                │                    stream.write(chunk)
    │                                │                    ← widget re-renders
```

Use Textual's `Markdown.get_stream()` (v4+) for flicker-free streaming:

```python
@work(thread=True)
async def stream_response(self, conversation_id: int) -> None:
    md_widget = self.query_one("#response-md", Markdown)
    container = self.query_one("#message-list", VerticalScroll)
    container.anchor()  # auto-scroll to bottom
    
    stream = Markdown.get_stream(md_widget)
    sse = SSEClient(...)
    
    try:
        for event_type, data in sse.events():
            if event_type == "text_chunk":
                chunk = data.get("chunk", "")
                if data.get("type") == "thinking":
                    self.post_message(ThinkingChunk(chunk))
                else:
                    await stream.write(chunk)
            elif event_type == "tool_request":
                await stream.stop()
                self.post_message(ToolRequested(data["tool_request"]))
            elif event_type == "completed":
                break
    finally:
        await stream.stop()
```

## Screens

### LoginScreen

Simple and functional. Two inputs, one button.

```
┌─────────────────────────────────────────┐
│                                         │
│          ╔═══════════════════╗           │
│          ║   Chinese Worker  ║           │
│          ╚═══════════════════╝           │
│                                         │
│          Email:    [____________]        │
│          Password: [____________]        │
│                                         │
│              [ Login ]                   │
│                                         │
│          status message here             │
│                                         │
└─────────────────────────────────────────┘
```

- Auto-skip if valid token exists in `~/.chinese-worker-cli-token.json`
- On success, push HomeScreen
- On failure, show error inline (red text below button)
- Enter in email → focus password. Enter in password → submit.

### HomeScreen

Agent picker. Clean, scannable list.

```
┌──────────────────────────────────────────────────┐
│  Chinese Worker            [user@email]   Ctrl+Q │
├──────────────────────────────────────────────────┤
│                                                  │
│  Select an Agent                                 │
│                                                  │
│  ┌────────────────────────────────────────────┐  │
│  │ ▸ CodeAssistant                            │  │
│  │   claude / claude-sonnet-4-5   8 tools     │  │
│  │   "Full-stack coding agent with..."        │  │
│  ├────────────────────────────────────────────┤  │
│  │   ResearchBot                              │  │
│  │   ollama / llama3.1            3 tools     │  │
│  │   "Web research and summarization"         │  │
│  ├────────────────────────────────────────────┤  │
│  │   ...                                      │  │
│  └────────────────────────────────────────────┘  │
│                                                  │
│  ↑↓ Navigate  Enter Select  R Refresh  Q Quit   │
└──────────────────────────────────────────────────┘
```

- Fetch agents on mount (with loading spinner)
- Arrow keys to navigate, Enter to select
- On select → push ChatScreen(agent)
- Show agent name, backend, model, tool count, description snippet

### ChatScreen (The Core)

This is where the magic happens. Three zones: status bar, message area, input.

```
┌──────────────────────────────────────────────────┐
│ CodeAssistant (claude-sonnet-4-5)    ● Connected │
├──────────────────────────────────────────────────┤
│                                                  │
│  You:                                            │
│  Create a Python script that reads CSV files     │
│                                                  │
│  ┌─ Assistant ─────────────────────────────────┐ │
│  │                                             │ │
│  │ I'll create a CSV reader for you. Let me    │ │
│  │ start by writing the script:                │ │
│  │                                             │ │
│  │ ```python                                   │ │
│  │ import csv                                  │ │
│  │ import sys                                  │ │
│  │ ```                                         │ │
│  │                                             │ │
│  │ ▍ (streaming cursor)                        │ │
│  └─────────────────────────────────────────────┘ │
│                                                  │
│  ⚙ Tool: bash                                   │
│  $ python csv_reader.py test.csv                 │
│  [Y]es  [N]o  [A]ll                             │
│                                                  │
├──────────────────────────────────────────────────┤
│ > Type your message... (/ for commands)   Ctrl+C │
└──────────────────────────────────────────────────┘
```

#### Message Area

- **User messages**: Plain text, right-aligned or left with "You:" prefix, subtle background
- **Assistant messages**: Full markdown rendering via Textual's `Markdown` widget with streaming support
- **Thinking blocks**: Collapsible, dimmed italic, shown above the response
- **Tool activity**: Inline status indicators for server-side tools (web_search, todos)
- **System messages**: Dimmed, centered (connection status, errors)
- Auto-scroll to bottom on new content (with `container.anchor()`)
- Scroll up to read history; new message snaps back to bottom

#### Tool Approval Flow

When the server requests a client-side tool:

1. SSE stream pauses (server sends `tool_request` event)
2. A tool approval panel appears inline in the message area (not a modal — modals break flow)
3. Shows: tool name, formatted arguments (syntax-highlighted for bash commands)
4. Keys: `Y` approve, `N` reject, `A` approve all future
5. On approve → execute tool in background thread → submit result → SSE reconnects
6. On reject → submit rejection → SSE reconnects

Why inline instead of modal: modals steal focus and feel jarring in a chat flow. An inline panel keeps context visible.

#### Input Area

- Single-line `Input` widget (not TextArea — keep it simple for Phase 1)
- Enter sends the message
- Shift+Enter for newline (if we switch to TextArea later)
- Disabled + visual indicator while agent is processing
- Slash commands: `/help`, `/stop`, `/clear`, `/exit`, `/tools`, `/approve-all`

#### Status Bar

Top dock. Shows:
- Agent name + model
- Connection status (●/○ with color)
- Token usage if available (from SSE stats events)
- Processing indicator (spinner or "Thinking...")

## Widgets to Build

### 1. `ChatMessage` (extends `Static`)

Wraps either a plain text user message or a Markdown widget for assistant responses.

```python
class ChatMessage(Static):
    role: str  # "user", "assistant", "system", "tool"
    
    def compose(self):
        if self.role == "assistant":
            yield Markdown("", id="content")  # will be streamed into
        else:
            yield Static(self._format_content(), id="content")
```

### 2. `ToolApprovalPanel` (extends `Static`)

Inline panel for tool approval. Not a modal.

```python
class ToolApprovalPanel(Static):
    def compose(self):
        yield Static(f"⚙ Tool: {self.tool_name}")
        yield Static(self._format_args())  # syntax highlighted
        yield Horizontal(
            Button("[Y]es", variant="success", id="approve"),
            Button("[N]o", variant="error", id="reject"),
            Button("[A]ll", variant="warning", id="approve-all"),
        )
```

### 3. `ThinkingBlock` (extends `Collapsible`)

```python
class ThinkingBlock(Collapsible):
    """Collapsible thinking/reasoning display."""
    def __init__(self, content: str):
        super().__init__(title="💭 Thinking...", collapsed=True)
        self.content = content
```

### 4. `StatusBar` (extends `Static`)

```python
class StatusBar(Static):
    agent_name: reactive[str] = reactive("")
    model: reactive[str] = reactive("")
    status: reactive[str] = reactive("Connected")
    is_processing: reactive[bool] = reactive(False)
```

## TCSS Styling

### `theme.tcss` — Shared Design Tokens

```css
/* Color palette — dark theme, warm accents */
$primary: #7c9bf5;
$primary-muted: #4a5a8a;
$accent: #f5a97f;
$success: #a6da95;
$warning: #eed49f;
$error: #ed8796;
$surface: #1e1e2e;
$surface-dim: #181825;
$panel: #24243a;
$text: #cdd6f4;
$text-muted: #6c7086;
$border: #313244;
```

### `chat.tcss` — Chat Screen

```css
#chat-container { height: 100%; }

#status-bar {
    dock: top;
    height: 1;
    background: $surface-dim;
    color: $text-muted;
    padding: 0 2;
}

#message-list {
    height: 1fr;
    padding: 0 1;
    scrollbar-gutter: stable;
}

.message-user {
    margin: 1 0 0 8;    /* indent from left to create visual hierarchy */
    padding: 0 1;
    color: $primary;
}

.message-assistant {
    margin: 1 0 0 0;
    padding: 1 2;
    background: $panel;
    border-left: thick $success;
}

.message-assistant.streaming {
    border-left: thick $warning;
}

.thinking-block {
    margin: 0 0 0 2;
    color: $text-muted;
}

.tool-panel {
    margin: 1 0;
    padding: 1 2;
    background: $surface-dim;
    border: solid $warning;
}

#input-area {
    dock: bottom;
    height: auto;
    max-height: 6;
    padding: 0 1;
    background: $surface-dim;
    border-top: solid $border;
}
```

## Implementation Order

Work in this sequence — each step builds on the previous and is testable:

### Step 1: App shell + Login (Day 1)
- `CWApp` with screen stack
- `LoginScreen` with auth flow
- Auto-skip to HomeScreen if token valid
- `cw` command launches the app
- **Test**: Launch → login → see "logged in" confirmation

### Step 2: HomeScreen + Agent list (Day 1-2)
- Fetch and display agents
- Keyboard navigation
- Select → push placeholder ChatScreen
- **Test**: Login → see agents → select one

### Step 3: ChatScreen scaffold (Day 2-3)
- Layout: status bar, empty message list, input
- Send message via API (blocking, in executor)
- Display user message in list
- No streaming yet — just wait for completion and show final response
- **Test**: Send message → see response (non-streaming)

### Step 4: SSE streaming (Day 3-4)
- Wire up SSE handler with `Markdown.get_stream()`
- Streaming text chunks render in real-time
- Handle completed/failed/cancelled events
- Thinking blocks accumulate and display
- **Test**: Send message → watch markdown stream in live

### Step 5: Tool execution (Day 4-5)
- ToolApprovalPanel widget
- Execute tool locally, submit result
- SSE reconnection after tool completion
- Auto-approve mode
- Server-side tool indicators (web_search, etc.)
- **Test**: Ask agent to run a command → approve → see result → conversation continues

### Step 6: Polish the core loop (Day 5-6)
- Error handling (network errors, API errors, timeouts)
- Ctrl+C interruption (stop conversation via API)
- Conversation creation on first message
- History loading when resuming
- Slash commands (/help, /stop, /clear, /exit, /tools)
- **Test**: Full end-to-end conversation with tool use, interruption, error recovery

## Files to Create

| File | Purpose |
|------|---------|
| `chinese_worker/main.py` | Click CLI entry point (replaces cli.py's entry) |
| `chinese_worker/config.py` | Config loader |
| `chinese_worker/tui/app.py` | CWApp class |
| `chinese_worker/tui/screens/login.py` | Login screen |
| `chinese_worker/tui/screens/home.py` | Agent list screen |
| `chinese_worker/tui/screens/chat.py` | Chat screen (the big one) |
| `chinese_worker/tui/widgets/message.py` | ChatMessage widget |
| `chinese_worker/tui/widgets/status_bar.py` | Status bar |
| `chinese_worker/tui/widgets/tool_panel.py` | Tool approval panel |
| `chinese_worker/tui/widgets/thinking.py` | Thinking block |
| `chinese_worker/tui/handlers/stream.py` | SSE → Textual bridge |
| `chinese_worker/tui/handlers/tools.py` | Tool execution orchestrator |
| `chinese_worker/tui/handlers/commands.py` | Slash command registry |
| `chinese_worker/tui/styles/theme.tcss` | Design tokens |
| `chinese_worker/tui/styles/login.tcss` | Login styles |
| `chinese_worker/tui/styles/home.tcss` | Home styles |
| `chinese_worker/tui/styles/chat.tcss` | Chat styles |

## Files to Modify

| File | Changes |
|------|---------|
| `pyproject.toml` | Bump textual to `>=4.0.0`, add entry point for `cw` |
| `chinese_worker/api/sse_client.py` | Keep as-is, used by stream handler |
| `chinese_worker/api/client.py` | No changes needed for Phase 1 |

## Files to Delete (Eventually)

The old TUI scaffolding under `tui/` — but don't delete until Phase 1 is complete and working. Keep the old `cli.py` accessible as `cw classic` for fallback during transition.

## Acceptance Criteria

- [X] `cw` launches the TUI
- [X] Login works (and auto-skips when token is fresh)
- [X] Agent list loads and is navigable
- [X] Chat: send a message, see streaming markdown response
- [X] Chat: thinking blocks are shown (collapsed by default)
- [X] Chat: tool approval works (approve, reject, approve-all) — fixed focus with call_after_refresh
- [X] Chat: server-side tools show status indicators
- [X] Chat: Ctrl+C stops the current generation
- [X] Chat: slash commands work (/help, /stop, /clear, /exit)
- [X] Chat: errors are displayed inline, never crash the app
- [X] Escape from chat → back to agent list
- [X] Visual design is cohesive and pleasant (dark theme, readable, no visual glitches)
