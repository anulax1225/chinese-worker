# Phase 2 — Conversation Management

> **Goal**: Browse, resume, search, and delete conversations without leaving the TUI. Add a sidebar to the chat screen for quick conversation switching.

## What You Get at the End

- Conversation list screen with filtering and search
- Resume any past conversation (loads history, handles pending tool requests)
- Delete conversations
- Chat sidebar: toggle a left panel showing recent conversations for the current agent
- Quick-switch between conversations without leaving the chat screen
- Conversation metadata visible (message count, token usage, last activity, status)

## Prerequisites

- Phase 1 complete and working

## Screens

### ConversationListScreen

Accessible from HomeScreen via a "Conversations" action, or from ChatScreen via `/conversations`.

```
┌──────────────────────────────────────────────────────┐
│  Conversations                        Filter ▾  Q ✕  │
├──────────────────────────────────────────────────────┤
│  🔍 [Search conversations...]                        │
│                                                      │
│  ┌──────────────────────────────────────────────────┐│
│  │ #142  CodeAssistant          12 msgs   2h ago    ││
│  │       "Implement the CSV parser with..."  active ││
│  ├──────────────────────────────────────────────────┤│
│  │ #138  ResearchBot             8 msgs   1d ago    ││
│  │       "Find papers on transformer..."  completed ││
│  ├──────────────────────────────────────────────────┤│
│  │ #135  CodeAssistant          45 msgs   3d ago    ││
│  │       "Refactor the auth module..."    active    ││
│  └──────────────────────────────────────────────────┘│
│                                                      │
│  ↑↓ Navigate  Enter Resume  D Delete  F Filter  / 🔍│
└──────────────────────────────────────────────────────┘
```

**Features:**
- List all conversations across agents (or filtered by agent)
- Show: ID, agent name, message count, last activity (relative time), status badge, first message preview
- Filter by status: active, completed, failed, cancelled (via Select widget or key)
- Search by content (uses listing API's search param if backend supports, otherwise client-side filter on first message)
- Keyboard: Enter to resume, D to delete (with confirmation), F to toggle filter
- Paginated loading for large lists

### ChatScreen Sidebar

A toggleable left panel within the existing ChatScreen. This is the faster way to switch conversations.

```
┌───────────────────┬──────────────────────────────────┐
│ Conversations     │ CodeAssistant (claude)  ● Online │
│                   ├──────────────────────────────────┤
│ + New             │                                  │
│                   │  You:                            │
│ ▸ #142 (active)   │  Fix the login bug               │
│   12 msgs, 2h ago │                                  │
│                   │  Assistant:                      │
│   #138 (done)     │  I'll look into the auth...      │
│   8 msgs, 1d ago  │                                  │
│                   │                                  │
│   #135 (active)   │                                  │
│   45 msgs, 3d ago │                                  │
│                   │                                  │
│                   ├──────────────────────────────────┤
│                   │ > Message...               Ctrl+B│
└───────────────────┴──────────────────────────────────┘
```

- Toggle with `Ctrl+B` (B for browse) or `/sidebar`
- Shows conversations for the current agent only
- Click or Enter on a conversation → switch to it (save current state, load new history)
- "+ New" button at top to start fresh conversation
- Active conversation highlighted
- Width: ~25 columns, collapsible

## Widgets to Build

### 1. `ConversationItem` (extends `Static`)

A single row in the conversation list. Displays metadata compactly.

```python
class ConversationItem(Static):
    """Single conversation in a list."""
    conversation: reactive[Dict] = reactive({})
    selected: reactive[bool] = reactive(False)
    
    def render(self) -> str:
        conv = self.conversation
        status_icon = {"active": "●", "completed": "✓", "failed": "✗", "cancelled": "○"}
        # ... format as rich text
```

### 2. `ConversationSidebar` (extends `Container`)

Left panel in the chat screen.

```python
class ConversationSidebar(Container):
    """Sidebar for quick conversation switching."""
    
    def compose(self):
        yield Button("+ New", id="new-conv", variant="primary")
        yield VerticalScroll(id="sidebar-list")
    
    async def load_conversations(self, agent_id: int):
        """Fetch and display conversations for this agent."""
```

### 3. `StatusBadge` (extends `Static`)

Tiny colored badge for conversation status.

```python
class StatusBadge(Static):
    """Colored status indicator."""
    STATUS_COLORS = {
        "active": "green",
        "completed": "blue", 
        "failed": "red",
        "cancelled": "yellow",
        "paused": "yellow",
    }
```

## Changes to Existing Code

### ChatScreen Modifications

- Add sidebar container (hidden by default)
- `Ctrl+B` binding to toggle sidebar
- `switch_conversation(conversation_id)` method:
  1. Stop any active SSE stream
  2. Clear message list
  3. Load conversation history
  4. Handle pending tool requests from previous session
  5. Update status bar
- Track conversation creation: lazy-create on first message (don't create empty conversations)

### HomeScreen Modifications

- Add "Conversations" button/action alongside agent list
- Keybinding: `C` to open ConversationListScreen

### API Client

No new endpoints needed — `list_conversations`, `get_conversation`, `delete_conversation` already exist.

## Conversation Resume Logic

Resuming a conversation that was interrupted requires careful handling:

```python
async def resume_conversation(self, conversation_id: int):
    """Resume a conversation, handling any pending state."""
    conv = await self.fetch_conversation(conversation_id)
    
    # 1. Display history
    await self.render_history(conv["messages"])
    
    # 2. Check for pending tool requests
    if conv["status"] == "paused" and conv.get("pending_tool_request"):
        # Show the pending tool request for approval
        await self.handle_tool_request(conv["pending_tool_request"])
    
    # 3. Check for unanswered tool calls in message history
    elif self.has_unanswered_tool_calls(conv["messages"]):
        tool_request = self.extract_pending_tool_call(conv["messages"])
        await self.handle_tool_request(tool_request)
    
    # 4. Ready for new input
    self.enable_input()
```

This logic already exists in the prototype's `handle_pending_tool_request()` — port it to the async TUI.

## Styling

### `conversations.tcss`

```css
.conversation-item {
    padding: 1 2;
    margin: 0 0 0 0;
    height: auto;
    border-bottom: solid $border;
}

.conversation-item.selected {
    background: $primary-muted;
}

.conversation-item:hover {
    background: $surface-dim;
}

.status-active { color: $success; }
.status-completed { color: $primary; }
.status-failed { color: $error; }

#sidebar {
    width: 28;
    background: $surface-dim;
    border-right: solid $border;
    display: none;  /* toggled via CSS class */
}

#sidebar.visible {
    display: block;
}
```

## Slash Commands (New)

| Command | Action |
|---------|--------|
| `/conversations` | Open ConversationListScreen |
| `/sidebar` | Toggle sidebar |
| `/new` | Create new conversation with current agent |
| `/switch <id>` | Switch to conversation by ID |
| `/delete` | Delete current conversation (with confirmation) |
| `/info` | Show current conversation metadata |

## Implementation Order

### Step 1: ConversationListScreen (Day 1-2)
- Fetch conversations from API
- Render list with metadata
- Navigate, select to resume, delete with confirmation
- Wire up from HomeScreen

### Step 2: Conversation resume (Day 2-3)
- Load and render message history in ChatScreen
- Handle pending tool requests
- Handle completed/failed state gracefully

### Step 3: Sidebar (Day 3-4)
- Build ConversationSidebar widget
- Integrate into ChatScreen layout
- Ctrl+B toggle
- Click to switch
- New conversation button

### Step 4: Polish (Day 4-5)
- Relative timestamps ("2h ago", "yesterday")
- First message preview (truncated)
- Status filtering on ConversationListScreen
- Smooth transitions when switching conversations

## Acceptance Criteria

- [ ] ConversationListScreen shows all conversations with metadata
- [ ] Can filter conversations by status
- [ ] Can resume a conversation (history loads, pending tools handled)
- [ ] Can delete a conversation (with confirmation)
- [ ] Sidebar toggles with Ctrl+B in ChatScreen
- [ ] Sidebar shows current agent's conversations
- [ ] Can switch conversations from sidebar without going back to home
- [ ] New conversation from sidebar works
- [ ] Active conversation is highlighted in sidebar
- [ ] Slash commands for conversation management work
