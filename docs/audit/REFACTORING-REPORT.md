# Consolidated Refactoring Report

> Generated from audit second pass - All 151 checklist items reviewed

## Executive Summary

| Severity | Count |
|----------|-------|
| Critical | 1 |
| High | 4 |
| Medium | 12 |
| Low | 34 |
| **Total** | **51** |

---

## Critical Priority

### CLI-SEC-001: Token File Permissions
- **Location**: `cw-cli/chinese_worker/api/auth.py:51-56`
- **Issue**: Token file created without restricted permissions (`0o600`). Other users on the system may read the auth token.
- **Fix**:
  ```python
  import os
  import stat

  def set_token(cls, token: str) -> None:
      token_file = cls._get_token_path()
      token_file.parent.mkdir(parents=True, exist_ok=True)
      token_file.write_text(json.dumps({"token": token}))
      os.chmod(token_file, stat.S_IRUSR | stat.S_IWUSR)  # 0o600
  ```

---

## High Priority

### SEC-003: API Rate Limiting
- **Location**: `bootstrap/app.php`
- **Issue**: **NO API rate limiting configured**. Only `statefulApi()` is used, no throttle middleware. Login has Fortify limits but API routes are unprotected.
- **Fix**:
  ```php
  // bootstrap/app.php
  ->withMiddleware(function (Middleware $middleware) {
      $middleware->statefulApi();
      $middleware->throttleApi('60,1');  // 60 requests per minute
  })
  ```

### SEC-005: Dangerous Patterns Not Enforced
- **Location**: `config/agent.php` → `dangerous_patterns`
- **Issue**: **CRITICAL: `dangerous_patterns` are defined but NEVER checked anywhere in codebase**. The server trusts the CLI to validate commands.
- **Fix**: Either remove the config (if CLI is the enforcement point) or add server-side validation in `ToolService` before execution.

### SEC-006: Path Restrictions Not Enforced
- **Location**: `config/agent.php` → `denied_paths`
- **Issue**: **CRITICAL: `denied_paths` like `.env` are defined but check is NEVER performed**. Server doesn't validate file paths.
- **Fix**: Add path validation in server-side tool handlers or document that CLI is the sole enforcement point.

### CLI-QA-001: No Test Coverage
- **Location**: `cw-cli/tests/` (missing)
- **Issue**: No tests exist for CLI. The entire Python CLI has zero test coverage.
- **Fix**: Add pytest test suite:
  ```
  cw-cli/tests/
  ├── conftest.py
  ├── test_api_client.py
  ├── test_sse_client.py
  ├── test_tools/
  │   ├── test_bash.py
  │   ├── test_read.py
  │   └── test_write.py
  └── test_tui/
  ```

---

## Medium Priority

### Security

| ID | Issue | Location | Fix |
|----|-------|----------|-----|
| SEC-001 | Token expiration is `null` - tokens never expire | `config/sanctum.php:50` | Set `expiration` to `1440` (24 hours) |
| SEC-002 | SSRF protection disabled by default | `config/agent.php:118` | Enable `TOOLS_BLOCK_PRIVATE_IPS=true` in production |
| CLI-SEC-002 | Read tool has no path validation | `cw-cli/chinese_worker/tools/read.py` | Add path blacklist check before reading |
| CLI-SEC-003 | Write tool has no path validation | `cw-cli/chinese_worker/tools/write.py` | Add path blacklist check before writing |
| CLI-SEC-008 | Systemctl service name injection | `cw-cli/chinese_worker/tools/systemctl.py` | Validate service name with regex `^[a-zA-Z0-9@._-]+$` |
| CLI-SEC-011 | AppleScript unrestricted | `cw-cli/chinese_worker/tools/applescript.py` | Document risk, consider blocking dangerous commands |

### Quality

| ID | Issue | Location | Fix |
|----|-------|----------|-----|
| CLI-QA-002 | Large single file | `cw-cli/chinese_worker/cli.py` (1160+ lines) | Split into `cli/commands/`, `cli/handlers/`, etc. |
| WEB-QA-002 | No frontend tests | `resources/js/` | Add Vitest + Vue Test Utils |
| INT-002 | No WebSocket conversation channels | `routes/channels.php` | Add `conversation.{id}` channel if WebSocket fallback needed |

### Performance

| ID | Issue | Location | Fix |
|----|-------|----------|-----|
| PERF-001 | Missing lazy loading protection | `app/Providers/AppServiceProvider.php` | Add `Model::preventLazyLoading(!$this->app->isProduction())` |
| CLI-PERF-001 | Read tool loads entire file | `cw-cli/chinese_worker/tools/read.py:68` | Use generator pattern with size limit |
| WEB-PERF-001 | 5 font families loaded | `resources/views/app.blade.php:13-14` | Lazy load non-default fonts |
| WEB-PERF-002 | No message virtualization | `resources/js/pages/Conversations/Show.vue` | Use `@vueuse/core` `useVirtualList` |

---

## Low Priority

### Backend Security
| ID | Issue | Location |
|----|-------|----------|
| SEC-004 | Password min 8 chars | `app/Actions/Fortify/PasswordValidationRules.php` |
| SEC-007 | CORS allows all origins | `config/cors.php` |

### Backend Quality
| ID | Issue | Location |
|----|-------|----------|
| QA-001 | ModelConfigNormalizer not injected | `app/Services/AIBackendManager.php` |
| QA-002 | HTTP Client created inline | `app/Services/ToolService.php` |
| QA-003 | String status instead of enums | Multiple models |

### Backend Performance
| ID | Issue | Location |
|----|-------|----------|
| PERF-002 | Large columns in listings | `ConversationController` queries |
| PERF-003 | Singleton services under Octane | `AppServiceProvider` |

### CLI Security
| ID | Issue | Location |
|----|-------|----------|
| CLI-SEC-004 | Write tool no backup | `cw-cli/chinese_worker/tools/write.py` |
| CLI-SEC-005 | 5-hour API timeout | `cw-cli/chinese_worker/api/client.py:11` |
| CLI-SEC-006 | No environment isolation | `cw-cli/chinese_worker/tools/bash.py` |
| CLI-SEC-007 | No file size limit | `cw-cli/chinese_worker/tools/read.py` |
| CLI-SEC-009 | Windows clipboard injection | `cw-cli/chinese_worker/tools/clipboard.py` |
| CLI-SEC-010 | Windows notification XML injection | `cw-cli/chinese_worker/tools/notify.py` |

### CLI Quality
| ID | Issue | Location |
|----|-------|----------|
| CLI-QA-003 | Weak typing (Dict[str, Any]) | Throughout `cw-cli/` |
| CLI-QA-004 | No auto re-auth on 401 | `cw-cli/chinese_worker/api/client.py` |
| CLI-QA-005 | Deep nesting in handlers | `cw-cli/chinese_worker/cli.py` |

### CLI Performance
| ID | Issue | Location |
|----|-------|----------|
| CLI-PERF-002 | Bash output not streamed | `cw-cli/chinese_worker/tools/bash.py` |
| CLI-PERF-003 | No SSE auto-reconnect | `cw-cli/chinese_worker/api/sse_client.py` |
| CLI-PERF-004 | No retry with backoff | `cw-cli/chinese_worker/api/client.py` |
| CLI-PERF-005 | Auth reads file each call | `cw-cli/chinese_worker/api/auth.py` |
| CLI-PERF-006 | Unbounded message list | `cw-cli/chinese_worker/tui/widgets/message_list.py` |

### Webapp Security
| ID | Issue | Location |
|----|-------|----------|
| WEB-SEC-001 | QR code v-html | `resources/js/pages/Settings/TwoFactor.vue` |
| WEB-SEC-002 | No CSP headers | Server configuration |
| WEB-SEC-003 | npm audit not in CI | CI/CD pipeline |
| WEB-SEC-004 | Console.log in production | `resources/js/pages/Conversations/Show.vue:240` |

### Webapp Quality
| ID | Issue | Location |
|----|-------|----------|
| WEB-QA-001 | Large page component | `resources/js/pages/Conversations/Show.vue` |
| WEB-QA-003 | ESLint/Prettier not verified | CI/CD pipeline |

### Webapp Performance
| ID | Issue | Location |
|----|-------|----------|
| WEB-PERF-003 | No vendor chunk config | `vite.config.ts` |
| WEB-PERF-004 | No scroll debouncing | `resources/js/pages/Conversations/Show.vue` |
| WEB-PERF-005 | No shallowRef for arrays | `resources/js/pages/Conversations/Show.vue` |

### Integration
| ID | Issue | Location |
|----|-------|----------|
| INT-001 | TypeScript Agent type mismatch | `resources/js/types/models.ts` |
| INT-003 | CLI token expiry not handled | `cw-cli/chinese_worker/api/client.py` |
| INT-004 | No upload progress tracking | Both CLI and Web |
| INT-005 | API docs minor inconsistency | `docs/guide/api-overview.md` |
| INT-006 | Scribe not configured | Composer/routes |

---

## Recommended Refactoring Order

### Phase 1: Critical Security (Immediate)
1. **CLI-SEC-001**: Fix token file permissions
2. **SEC-003**: Add API rate limiting
3. **SEC-005/SEC-006**: Document or enforce security patterns

### Phase 2: High Priority (Sprint 1)
1. **CLI-QA-001**: Add CLI test infrastructure
2. **SEC-001**: Set token expiration
3. **SEC-002**: Enable SSRF protection in production

### Phase 3: Medium Priority (Sprint 2)
1. **PERF-001**: Add lazy loading protection
2. **WEB-PERF-002**: Add message virtualization
3. **CLI-QA-002**: Refactor cli.py into modules

### Phase 4: Low Priority (Backlog)
- Add frontend tests
- Improve typing in CLI
- Optimize Vite chunking
- Add CSP headers

---

## Architecture Notes

### Security Model
The current architecture trusts the CLI for tool validation:
- `dangerous_patterns` and `denied_paths` in config are **NOT enforced server-side**
- CLI tools execute locally with user approval
- Server only receives tool results, not commands

This is acceptable for a self-hosted tool but should be documented clearly.

### Real-Time Architecture
- **SSE via Redis RPUSH/BLPOP** is the sole real-time mechanism
- WebSocket/Reverb is configured but unused for conversations
- Both CLI and Web use EventSource for streaming

### Test Coverage
| System | Coverage |
|--------|----------|
| Backend | 47 test files (good) |
| CLI | 0 tests (critical gap) |
| Webapp | 0 frontend tests (gap) |
