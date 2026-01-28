# Context Documentation

This folder contains documentation for continuing Chinese Worker development across conversation sessions.

## Files in This Folder

### 1. [PLAN.md](PLAN.md)
**Complete architecture plan for the entire project**

Contains:
- Core architecture principles
- Tool categories (builtin, system, user)
- All implementation phases (1-6)
- Database schema
- API response formats
- Security considerations
- Technology stack
- Success metrics

**Use when**: You need to understand the big picture or reference future phases.

### 2. [CURRENT_STATE.md](CURRENT_STATE.md)
**Detailed snapshot of what's implemented**

Contains:
- Completed phases (1, 2, 2.5)
- All implemented features
- File structure
- How the system currently works
- Testing status
- Known limitations
- Configuration details
- Performance characteristics

**Use when**: You need to know what's done, what exists, and what works.

### 3. [PHASE_3_START_PROMPT.md](PHASE_3_START_PROMPT.md)
**Ready-to-use prompt for starting Phase 3**

Contains:
- Project context and overview
- What's complete (Phases 1, 2, 2.5)
- Phase 3 objectives in detail
- Implementation checklist
- Database schema to add
- Important patterns and conventions
- Files to reference

**Use when**: Starting a new conversation to continue with Phase 3.

## How to Use These Files

### Starting Phase 3 in a New Conversation

1. Copy the entire content of [PHASE_3_START_PROMPT.md](PHASE_3_START_PROMPT.md)
2. Paste it into a new Claude conversation
3. Begin implementing Phase 3 features

The prompt includes all necessary context to continue seamlessly.

### Understanding the Project

1. Start with [CURRENT_STATE.md](CURRENT_STATE.md) to see what exists
2. Read [PLAN.md](PLAN.md) to understand the full vision
3. Reference specific sections as needed during development

### Continuing After Phase 3

When Phase 3 is complete:
1. Update [CURRENT_STATE.md](CURRENT_STATE.md) with Phase 3 completion
2. Create a new `PHASE_4_START_PROMPT.md` following the same pattern
3. Keep this documentation folder updated

## Quick Reference

**Backend Key Files**:
- `app/Services/ConversationService.php` - Agentic loop
- `app/Http/Controllers/Api/V1/ConversationController.php` - API
- `app/Models/Conversation.php` - Conversation model

**CLI Key Files**:
- `cli/chinese_worker/cli.py` - Main CLI (500+ lines)
- `cli/chinese_worker/api/client.py` - API client
- `cli/chinese_worker/tools/` - Builtin tools

**Testing**:
```bash
# Backend tests
./vendor/bin/sail artisan test

# CLI tool tests
cd cli && python test_tools.py
```

**Commands**:
```bash
# Start backend
./vendor/bin/sail up -d

# Install CLI
cd cli && pip install -e .

# Use CLI
cw login
cw agents
cw chat 1
```

## Project Status

**Current Phase**: Phase 2.5 Complete ✅
**Next Phase**: Phase 3 - User Tools & Advanced Features
**Overall Progress**: ~30% (3 of 6+ phases complete)

## Related Documentation

- `/QUICKSTART.md` - 5-minute getting started guide
- `/cli/README.md` - CLI documentation
- `/PHASE_2.5_SUMMARY.md` - Phase 2.5 improvements
- `/TODO.md` - Original project notes

## Maintenance

**When to Update**:
- After completing each phase
- When significant features are added
- When architecture decisions change
- Before starting a new phase

**Who Updates**:
- Development team
- AI assistant (Claude)
- Project maintainers

## Contributing

When updating these files:
1. Keep them accurate and current
2. Be detailed but concise
3. Include code examples where helpful
4. Update cross-references between files
5. Maintain consistent formatting

---

**Last Updated**: 2026-01-28
**Status**: Phases 1, 2, and 2.5 Complete ✅
