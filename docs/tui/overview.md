# CW TUI — Refactoring Master Plan

## Vision

Replace the current prototype CLI and incomplete TUI with a single, polished Textual-based terminal application that serves as the primary interface for the Chinese Worker agentic platform. The chat experience is the heart of the product; everything else supports it.

## Design Philosophy

- **Chat-first**: The chat screen is where you spend 90% of your time. It must feel fluid, responsive, and beautiful.
- **Progressive disclosure**: Start simple, reveal complexity on demand. No screen should overwhelm.
- **Keyboard-native**: Every action reachable by keyboard. Mouse is a bonus, not a requirement.
- **Incremental delivery**: Each phase produces a working, shippable binary. No "big bang" rewrite.
- **Reuse what works**: The existing API client, tool implementations, and SSE infrastructure are solid. Refactor, don't rewrite from scratch.

## Technology Stack

| Layer | Choice | Rationale |
|-------|--------|-----------|
| **Framework** | Textual ≥ 4.0 | Native streaming Markdown via `Markdown.get_stream()`, CSS-based styling, async-first, screen stack |
| **HTTP** | httpx (async) | Already in use. Migrate synchronous calls to async where beneficial |
| **Streaming** | SSE (primary), polling (fallback) | Already implemented. Wrap in async adapter for Textual workers |
| **Styling** | TCSS (Textual CSS) | Separate `.tcss` files per screen, shared theme variables |
| **CLI entry** | Click (thin launcher) | Keep `cw` command: `cw tui` (default), `cw chat <id>` (quick), `cw login`, etc. |
| **Config** | `~/.config/cw/config.toml` | API URL, theme, keybindings, auto-approve settings |
| **Package** | pyproject.toml + hatchling | Already configured |

## Architecture (Target)

```
cw-cli/
├── chinese_worker/
│   ├── __init__.py
│   ├── main.py                  # Click CLI entry (thin)
│   ├── config.py                # Config loading (TOML)
│   ├── api/
│   │   ├── __init__.py
│   │   ├── auth.py              # Auth manager (keep)
│   │   ├── client.py            # API client (extend)
│   │   └── sse.py               # SSE client + async adapter
│   ├── tools/
│   │   ├── ...                  # All existing tools (keep as-is)
│   ├── tui/
│   │   ├── app.py               # CWApp main application
│   │   ├── theme.py             # Theme definitions
│   │   ├── screens/
│   │   │   ├── login.py         # Login screen
│   │   │   ├── home.py          # Agent list + conversation picker
│   │   │   ├── chat.py          # Main chat (the core)
│   │   │   ├── conversations.py # Conversation browser
│   │   │   ├── documents.py     # Document management
│   │   │   ├── agents.py        # Agent configuration
│   │   │   └── backends.py      # Backend/model management
│   │   ├── widgets/
│   │   │   ├── message.py       # Chat message (markdown streaming)
│   │   │   ├── input_area.py    # Multi-line input with keybinds
│   │   │   ├── status_bar.py    # Connection + agent + token status
│   │   │   ├── tool_panel.py    # Tool approval + execution display
│   │   │   ├── sidebar.py       # Conversation/agent sidebar
│   │   │   ├── thinking.py      # Thinking/reasoning display
│   │   │   └── command_palette.py
│   │   ├── handlers/
│   │   │   ├── stream.py        # SSE → widget bridge
│   │   │   ├── tools.py         # Tool execution orchestrator
│   │   │   └── commands.py      # Slash command registry
│   │   └── styles/
│   │       ├── theme.tcss       # Shared variables
│   │       ├── chat.tcss
│   │       ├── home.tcss
│   │       └── ...
│   └── utils/
│       ├── markdown.py          # Markdown helpers
│       └── formatting.py        # Rich formatting utilities
├── pyproject.toml
├── README.md
└── tests/
```

## Phase Overview

| Phase | Name | Deliverable | Backend Changes |
|-------|------|-------------|-----------------|
| **1** | Foundation & Core Chat | Fully working chat TUI with SSE streaming, markdown rendering, tool approval | None |
| **2** | Conversation Management | Browse, resume, search, delete conversations; sidebar | None |
| **3** | Documents & RAG | Upload, browse, attach documents; see RAG in action | Add client methods |
| **4** | Summaries & Memory | Trigger summaries, search conversation memory, see context usage | Add client methods |
| **5** | Agent & Backend Config | Create/edit agents, manage models, switch backends | None |
| **6** | Polish & Power Features | Command palette, themes, notifications, multi-pane, config file | None |

## Dependency Graph

```
Phase 1 (Foundation)
  ├── Phase 2 (Conversations)
  │     └── Phase 3 (Documents)
  │           └── Phase 4 (Summaries & Memory)
  └── Phase 5 (Agent Config) — can run parallel to 3/4
        └── Phase 6 (Polish) — after all others
```

## What We Keep From the Prototype

- **All tool implementations** (`tools/` directory) — battle-tested, cross-platform
- **API client** (`api/client.py`) — extend, don't rewrite
- **SSE client** (`api/sse_client.py`) — wrap in async adapter
- **Auth manager** (`api/auth.py`) — works fine
- **Click CLI** — strip down to thin launcher

## What We Replace

- **`cli.py` chat loop** — replaced by Textual chat screen
- **Rich Live display** — replaced by Textual streaming Markdown widget
- **prompt_toolkit input** — replaced by Textual Input/TextArea
- **All TUI scaffolding** (`tui/`) — rewrite from scratch with proper architecture
- **Click subcommands for backends/conversations/documents** — move into TUI screens

## Key Technical Decisions

1. **Textual ≥ 4.0 required** for `Markdown.get_stream()` — this gives us flicker-free streaming markdown rendering natively, which is the single most important UX feature.

2. **Async-first API calls** — Textual is async. Use `run_in_executor` for blocking httpx calls, or migrate critical paths to `httpx.AsyncClient`.

3. **Screen stack, not screen switching** — push/pop screens so "back" always works. Chat screen stays mounted when browsing conversations.

4. **Worker threads for SSE** — keep the existing pattern of running SSE in a background thread with a queue bridge to Textual's async loop.

5. **TCSS per screen** — each screen gets its own stylesheet. A shared `theme.tcss` defines color variables and common patterns.

6. **Slash commands stay** — they're natural for power users. Extend with command palette (Phase 6).

## Definition of "Usable"

Each phase must satisfy:
- `cw` launches the TUI and you can do the phase's core task end-to-end
- No crashes on happy path
- Keyboard navigation works for all actions
- Visual feedback for all async operations (spinners, status text)
- Errors shown inline, never swallowed silently