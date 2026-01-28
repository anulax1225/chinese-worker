# Chinese Worker - Project Context

This file points you to the complete project context documentation for continuing development.

## 📁 Context Documentation Location

All project context is stored in: **`/docs/context/`**

## 🚀 Quick Start for New Conversation

To continue with **Phase 3** in a new conversation:

1. Open: [`/docs/context/PHASE_3_START_PROMPT.md`](docs/context/PHASE_3_START_PROMPT.md)
2. Copy the entire file contents
3. Paste into a new Claude conversation
4. Start building Phase 3!

## 📚 Available Documentation

### In `/docs/context/`:

| File | Purpose | When to Use |
|------|---------|-------------|
| [**PLAN.md**](docs/context/PLAN.md) | Complete architecture plan (all 6+ phases) | Understanding big picture, referencing future phases |
| [**CURRENT_STATE.md**](docs/context/CURRENT_STATE.md) | Detailed snapshot of what's implemented | Knowing what exists, what works, current limitations |
| [**PHASE_3_START_PROMPT.md**](docs/context/PHASE_3_START_PROMPT.md) | Ready-to-use prompt for Phase 3 | Starting Phase 3 in new conversation |
| [**README.md**](docs/context/README.md) | Guide to the context folder | Understanding how to use context docs |

### In Project Root:

| File | Purpose |
|------|---------|
| [QUICKSTART.md](QUICKSTART.md) | 5-minute getting started guide |
| [PHASE_2.5_SUMMARY.md](PHASE_2.5_SUMMARY.md) | Phase 2.5 improvements summary |
| [cli/README.md](cli/README.md) | CLI documentation |
| [TODO.md](TODO.md) | Original project notes |

## 📊 Current Status

- ✅ **Phase 1**: Backend with Polling - COMPLETE
- ✅ **Phase 2**: CLI with Polling - COMPLETE
- ✅ **Phase 3.5**: CLI Polish - COMPLETE
- 🔜 **Phase 3**: User Tools & Advanced Features - NEXT
- ⏳ **Phase 4**: Server-Sent Events (SSE) - PLANNED
- ⏳ **Phase 5**: WebSocket - PLANNED
- ⏳ **Phase 6**: Multi-Agent Orchestration - PLANNED

## 🎯 What's Complete

### Backend
- ✅ Full conversation management API (7 endpoints)
- ✅ Server-managed agentic loop in ConversationService
- ✅ 6 system tools (todo operations)
- ✅ 22 passing tests
- ✅ Polling support for CLI

### CLI
- ✅ Complete Python CLI with 5 commands
- ✅ 6 working builtin tools (bash, read, write, edit, glob, grep)
- ✅ Conversation management (list, select, resume)
- ✅ Persistent conversation loop
- ✅ Excellent UX with Rich terminal UI
- ✅ Production-ready

## 🛠️ Quick Commands

```bash
# Backend
./vendor/bin/sail up -d                    # Start backend
./vendor/bin/sail artisan test            # Run tests
./vendor/bin/sail artisan migrate         # Run migrations

# CLI
cd cli && pip install -e .                # Install CLI
python test_tools.py                      # Test tools
cw login                                  # Login
cw chat 1                                 # Start chatting
```

## 📖 For New Developers

1. Read [QUICKSTART.md](QUICKSTART.md) first
2. Read [docs/context/CURRENT_STATE.md](docs/context/CURRENT_STATE.md) to understand what exists
3. Read [docs/context/PLAN.md](docs/context/PLAN.md) for the full vision
4. Start coding!

## 📝 For Continuing Development

**Starting a new phase?**
- Use the appropriate `PHASE_X_START_PROMPT.md` file
- Currently available: [PHASE_3_START_PROMPT.md](docs/context/PHASE_3_START_PROMPT.md)

**Need to understand something?**
- Check [CURRENT_STATE.md](docs/context/CURRENT_STATE.md) for implementation details
- Check [PLAN.md](docs/context/PLAN.md) for architecture decisions

**Adding new features?**
- Follow existing patterns in the codebase
- Update context documentation when done
- Write tests for all changes

## 🔄 Keeping Context Updated

After completing a phase:
1. Update [CURRENT_STATE.md](docs/context/CURRENT_STATE.md)
2. Create next phase start prompt (e.g., `PHASE_4_START_PROMPT.md`)
3. Update this file's status section
4. Commit changes

## 💡 Tips

- **Lost context?** Read [CURRENT_STATE.md](docs/context/CURRENT_STATE.md)
- **New conversation?** Use the phase start prompts
- **Architecture questions?** Check [PLAN.md](docs/context/PLAN.md)
- **Quick reference?** This file has the essentials

---

**Location**: `/docs/context/`
**Last Updated**: 2026-01-28
**Next Phase**: Phase 3 - User Tools & Advanced Features
