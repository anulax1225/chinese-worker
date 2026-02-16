# Python CLI - Security Audit

## Overview

This audit covers security aspects of the Python CLI including tool execution sandboxing, API client security, credential storage, input sanitization, and command injection prevention.

## Critical Files

| Category | Path |
|----------|------|
| Entry Point | `cw-cli/chinese_worker/cli.py` |
| API Client | `cw-cli/chinese_worker/api/client.py` |
| SSE Client | `cw-cli/chinese_worker/api/sse_client.py` |
| Auth | `cw-cli/chinese_worker/api/auth.py` |
| Tools | `cw-cli/chinese_worker/tools/` |
| Tool Handler | `cw-cli/chinese_worker/tui/handlers/tool_handler.py` |
| Config | `cw-cli/pyproject.toml` |

---

## Checklist

### 1. Tool Execution Security

#### 1.1 Bash Tool
- [x] **Command sanitization** - Verify command input handling
  - Reference: `cw-cli/chinese_worker/tools/bash.py:69`
  - Finding: Uses `subprocess.run(["bash", "-c", command])` - shell=False. Command passed to bash as single argument. This is the proper pattern for shell command execution.

- [x] **Shell selection** - Verify shell invocation method
  - Finding: Unix uses `["bash", "-c", command]`, Windows uses `["powershell", "-NoProfile", "-Command", command]`. No shell=True usage.

- [x] **Timeout enforcement** - Verify command timeout
  - Reference: `cw-cli/chinese_worker/tools/bash.py:64`
  - Finding: Default 120 seconds, configurable per-command. TimeoutExpired exception handled.

- [x] **Working directory control** - Verify CWD handling
  - Finding: Commands run in `os.getcwd()` - current working directory.

- [x] **Environment isolation** - Verify environment variables
  - Finding: **NOT IMPLEMENTED** - No explicit environment filtering. Child processes inherit parent environment including potentially sensitive variables.
  - Mitigated by user approval - user sees commands before execution.

#### 1.2 PowerShell Tool
- [x] **Script injection prevention** - Verify PowerShell safety
  - Reference: `cw-cli/chinese_worker/tools/powershell.py:55-56`
  - Finding: Uses `subprocess.run()` with list args (no shell=True). Uses `-NoProfile -NonInteractive` flags which limits attack surface.
  - No command filtering - relies on user approval flow for security.

- [x] **Execution policy handling** - Verify execution restrictions
  - Finding: No explicit `-ExecutionPolicy` flag - uses system default. User approval provides control.

#### 1.3 Read Tool
- [x] **Path traversal prevention** - Verify path validation
  - Reference: `cw-cli/chinese_worker/tools/read.py:58`
  - Finding: **MISSING** - No path traversal protection. Paths are made absolute but `../` sequences not validated. Can read any file the user has access to.
  - Mitigated by tool approval UI showing full path.

- [x] **Sensitive file protection** - Verify file restrictions
  - Finding: **MISSING** - No blocklist for sensitive files like `/etc/shadow`, `~/.ssh/*`, credential files.
  - Architecture relies on user approval to prevent sensitive file access.

- [x] **File size limits** - Verify size restrictions
  - Finding: Lines truncated at 2000 characters. However, no total file size limit - could load very large files into memory.

#### 1.4 Write Tool
- [x] **Path validation** - Verify write path safety
  - Reference: `cw-cli/chinese_worker/tools/write.py:61`
  - Finding: **MISSING** - No path validation. Auto-creates parent directories. Can write to any location user has permissions.
  - Mitigated by tool approval UI showing full path before write.

- [x] **Overwrite protection** - Verify file overwrite handling
  - Finding: **MISSING** - Silently overwrites existing files with no backup or confirmation.
  - Tool approval shows file path but doesn't indicate if file exists.

- [x] **Permission preservation** - Verify file permissions
  - Finding: Uses default permissions from `open()`. New files get umask-based permissions. Acceptable behavior.

#### 1.5 Edit Tool
- [x] **Input validation** - Verify edit operations
  - Reference: `cw-cli/chinese_worker/tools/edit.py:76-78`
  - Finding: Good validation - checks file exists, is a file, handles encoding errors.
  - No path restrictions (same as read/write tools).
  - Validates that old_string != new_string. Reports count of occurrences.

#### 1.6 Glob/Grep Tools
- [x] **Pattern safety** - Verify glob/regex patterns
  - Reference: `cw-cli/chinese_worker/tools/glob.py:71`, `grep.py:107`
  - Finding:
    - Glob: Uses `pathlib.glob()` - safe, no ReDoS risk.
    - Grep: Uses Python `re.compile()`. Catches `re.error` for invalid patterns.
    - Potential ReDoS for complex patterns, but limited by Python's regex engine.
    - Grep skips files on UnicodeDecodeError/PermissionError - safe.

#### 1.7 AppleScript Tool
- [x] **Script injection** - Verify AppleScript safety
  - Reference: `cw-cli/chinese_worker/tools/applescript.py:54-66`
  - Finding: Executes arbitrary AppleScript via `osascript -e script`.
  - **No script validation** - can perform any macOS automation (keystroke injection, file access, app control).
  - Mitigated by user approval. 60-second timeout limits impact.

#### 1.8 Systemctl Tool
- [x] **Service restrictions** - Verify systemctl safety
  - Reference: `cw-cli/chinese_worker/tools/systemctl.py:29`
  - Finding: Actions restricted to enum: status, start, stop, restart, enable, disable, list, logs.
  - Uses `sudo` for privileged operations when needed.
  - **Potential command injection** if service name contains shell metacharacters (e.g., `nginx; rm -rf /`).
  - Recommendation: Validate service name against safe pattern `[a-zA-Z0-9_-]+`.

---

### 2. API Client Security

#### 2.1 Authentication
- [x] **Token transmission** - Verify token handling
  - Reference: `cw-cli/chinese_worker/api/client.py:32`
  - Finding: Token sent in Authorization header as `Bearer {token}`. Proper HTTP auth pattern.

- [x] **Token refresh handling** - Verify expiration handling
  - Finding: **MISSING** - No token refresh logic. If token expires, user must re-login.
  - Server-side: Sanctum tokens set to never expire (per backend audit finding SEC-001).
  - Combined effect: No refresh needed since tokens don't expire.

- [x] **Credential prompting** - Verify password handling
  - Reference: Login handled via API - password sent once to get token.
  - Token stored locally. No password caching in CLI.
  - TUI uses Textual Input widget - standard secure input handling.

#### 2.2 Request Security
- [x] **HTTPS verification** - Verify certificate validation
  - Reference: `cw-cli/chinese_worker/api/client.py`
  - Finding: Uses httpx defaults which enable SSL verification. However, no explicit `verify=True` to prevent accidental disabling.

- [x] **Request timeout** - Verify timeouts set
  - Reference: `cw-cli/chinese_worker/api/client.py:11`
  - Finding: Default timeout `5 * 60 * 60 * 60` = **5 hours**. This is extremely long and should be reduced.

- [x] **Error response handling** - Verify error safety
  - Finding: Uses `raise_for_status()` - exceptions raised for HTTP errors. No sensitive info leakage in error handling.

#### 2.3 SSE Client Security
- [x] **Stream authentication** - Verify SSE auth
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:28`
  - Finding: SSE client receives headers parameter and includes auth token.

- [x] **Reconnection security** - Verify reconnect handling
  - Finding: SSE client doesn't auto-reconnect - uses provided headers for each connection attempt.

---

### 3. Credential Storage

#### 3.1 Token Storage
- [x] **Storage location** - Verify token storage
  - Reference: `cw-cli/chinese_worker/api/auth.py:10-19`
  - Finding: Platform-specific paths:
    - Windows: `%APPDATA%/chinese-worker/token.json`
    - macOS: `~/Library/Application Support/chinese-worker/token.json`
    - Linux: `~/.cw/token.json`

- [x] **File permissions** - Verify token file permissions
  - Reference: `cw-cli/chinese_worker/api/auth.py:51-56`
  - Finding: **CRITICAL** - Token file created with default permissions. No `os.chmod(0o600)` call. Any user on the system could potentially read the token. Documented in CLI-SEC-001.

- [x] **Token format** - Verify token file format
  - Finding: Plain JSON file with `{"token": "..."}`. Acceptable given proper file permissions.

#### 3.2 Configuration Security
- [x] **No hardcoded secrets** - Verify no embedded secrets
  - Finding: No API keys or passwords found in source code.

- [x] **Environment variable handling** - Verify env var usage
  - Finding: Uses environment for configuration. No sensitive values exposed in CLI args.

---

### 4. Input Sanitization

#### 4.1 User Input
- [x] **Message input** - Verify message handling
  - Reference: `cw-cli/chinese_worker/tui/widgets/input_area.py`
  - Finding: Uses Textual's Input widget (extends base class). Safe from injection - text sent as-is to API.

- [x] **Command parsing** - Verify command input
  - Reference: `cw-cli/chinese_worker/tui/handlers/command_handler.py:26-48`
  - Finding: Commands parsed via `split(maxsplit=1)`. Safe pattern.
  - Known commands only - unknown commands produce message, no execution.
  - `/approve-all` sets flag - documented security feature, not vulnerability.

#### 4.2 Server Response
- [x] **Response validation** - Verify API response handling
  - Finding: JSON responses parsed with json.loads(). Malformed JSON caught and handled gracefully.

- [x] **SSE event parsing** - Verify event safety
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:68-72`
  - Finding: JSONDecodeError caught, malformed events skipped. No code execution from event data.

---

### 5. Tool Approval Flow

#### 5.1 Approval UI
- [x] **Tool request display** - Verify clear display
  - Reference: `cw-cli/chinese_worker/tui/widgets/tool_approval.py:51-75`
  - Finding: Well implemented. Shows tool name and formatted arguments:
    - Bash: Shows command with `$ ` prefix
    - Read/Write/Edit: Shows file path and content preview
    - Glob/Grep: Shows pattern
    - Generic: Shows key-value pairs truncated to 80 chars

- [x] **Approval required** - Verify approval enforcement
  - Reference: `cw-cli/chinese_worker/tui/widgets/tool_approval.py:12-20`
  - Finding: Modal dialog with Y/N/A keybindings. Escape defaults to reject.
  - User must explicitly approve (Y) or approve-all (A) for tool to execute.

- [x] **Deny option** - Verify denial works
  - Reference: `cw-cli/chinese_worker/tui/widgets/tool_approval.py:91-93`
  - Finding: "No" button and N keybinding calls `dismiss("no")`. Properly rejects tool execution.

#### 5.2 Tool Registry
- [x] **Registry security** - Verify tool registration
  - Reference: `cw-cli/chinese_worker/tools/registry.py`
  - Finding: Registry exists with predefined tools. Dynamic tool registration not reviewed.

---

### 6. Dependency Security

#### 6.1 Package Dependencies
- [x] **Dependency list** - Review dependencies
  - Reference: `cw-cli/pyproject.toml`
  - Finding: Well-known packages: click, httpx, psutil, prompt_toolkit, python-dotenv, rich, textual. All maintained and reputable.

- [x] **Version pinning** - Verify version constraints
  - Finding: Minimum versions specified (`>=8.1.0`). Not exact pins, but provides compatibility flexibility while setting lower bounds.

- [x] **Vulnerability check** - Run security audit
  - Status: Manual review only. Recommend running `pip-audit` or `safety check` as part of CI.
  - Dependencies: click, httpx, psutil, prompt_toolkit, python-dotenv, rich, textual - all well-maintained.

---

### 7. Clipboard Security

- [x] **Clipboard tool safety** - Verify clipboard handling
  - Reference: `cw-cli/chinese_worker/tools/clipboard.py:68-108`
  - Finding:
    - macOS: Uses `pbcopy`/`pbpaste` - safe, text passed via stdin.
    - Linux: Uses `xclip`/`xsel` - safe, text passed via stdin.
    - **Windows**: Uses PowerShell `Set-Clipboard -Value '{text}'` - **potential injection** if text contains single quotes or PowerShell escape sequences.
    - Recommendation: Use stdin for Windows too: `$input | Set-Clipboard`

---

### 8. Notification Security

- [x] **Notification content** - Verify notification safety
  - Reference: `cw-cli/chinese_worker/tools/notify.py:75-94`
  - Finding:
    - macOS: Escapes quotes in AppleScript (`replace('"', '\\"')`). Safe.
    - Linux: Uses `notify-send` with args - safe, no shell interpolation.
    - **Windows**: Uses XML template and PowerShell - title/message injected into XML without escaping. **Potential XML injection** for toast notifications.

---

## Findings

| ID | Item | Severity | Finding | Status |
|----|------|----------|---------|--------|
| CLI-SEC-001 | Token file permissions | Critical | Token file created without restricted permissions (`0o600`). Other users on the system may read the auth token. | Open |
| CLI-SEC-002 | Read tool path traversal | Medium | No path validation - can read sensitive files like `/etc/shadow`, `~/.ssh/id_rsa`, etc. Mitigated by user approval. | Open |
| CLI-SEC-003 | Write tool path validation | Medium | No restrictions on write paths - can overwrite system files or write to sensitive locations. Mitigated by user approval. | Open |
| CLI-SEC-004 | Write tool no backup | Low | Files overwritten without confirmation or backup. | Open |
| CLI-SEC-005 | API timeout excessive | Low | Default 5-hour timeout is extremely long. Should be reduced for most operations. | Open |
| CLI-SEC-006 | No environment isolation | Low | Bash tool inherits parent environment. Sensitive env vars may be exposed to commands. | Open |
| CLI-SEC-007 | No file size limit | Low | Read tool has no total file size limit - could exhaust memory on large files. | Open |
| CLI-SEC-008 | Systemctl service injection | Medium | Service name passed directly to subprocess - could contain shell metacharacters. | Open |
| CLI-SEC-009 | Windows clipboard injection | Low | PowerShell Set-Clipboard uses single-quoted text - potential injection with special characters. | Open |
| CLI-SEC-010 | Windows notification XML injection | Low | Title/message injected into XML template without escaping - potential XML injection. | Open |
| CLI-SEC-011 | AppleScript unrestricted | Medium | AppleScript tool can execute arbitrary macOS automation. Mitigated by approval flow. | Open |

---

## Recommendations

### Critical

1. **Fix Token File Permissions**: Add to `auth.py`:
   ```python
   def set_token(cls, token: str) -> None:
       token_file = cls._token_file()
       token_file.parent.mkdir(parents=True, exist_ok=True)
       with open(token_file, "w") as f:
           json.dump({"token": token}, f)
       os.chmod(token_file, 0o600)  # Add this line
   ```

### Medium Priority

2. **Validate Systemctl Service Names**: Add validation in `systemctl.py`:
   ```python
   import re
   if service and not re.match(r'^[a-zA-Z0-9_\-@.]+$', service):
       return False, "", "Invalid service name"
   ```

3. **Fix Windows Clipboard Injection**: Use stdin instead of inline value:
   ```python
   process = subprocess.run(
       ["powershell", "-NoProfile", "-Command", "$input | Set-Clipboard"],
       input=text, capture_output=True, text=True,
   )
   ```

4. **Fix Windows Notification XML Injection**: Escape XML characters:
   ```python
   from html import escape
   title = escape(title)
   message = escape(message)
   ```

### Low Priority

5. **Add Path Validation to Read/Write Tools**: Implement path validation:
   - Resolve paths and check for `..` traversal
   - Block known sensitive paths (`/etc/passwd`, `~/.ssh/*`, etc.)
   - Note: User approval provides primary protection

6. **Add Write Confirmation**: Show indicator in tool approval when overwriting existing files.

7. **Reduce API Timeout**: Change default from 5 hours to 30-60 seconds for most operations.

8. **Environment Isolation**: Consider filtering environment variables passed to subprocess.

9. **Add File Size Limits**: Limit read operations to 10MB to prevent memory exhaustion.

10. **Run Dependency Audit**: Add `pip-audit` to CI pipeline.

## Summary

The CLI has several security concerns that should be addressed:

**Critical**:
- Token file permissions allow other system users to read credentials

**Medium**:
- Read/write/edit tools have no path restrictions - AI could access/modify sensitive files
- Systemctl tool service name not validated - potential command injection
- AppleScript tool allows arbitrary macOS automation
- These are all mitigated by user approval flow

**Low**:
- Windows-specific injection issues in clipboard/notification tools
- Excessive API timeout
- No environment isolation for subprocess

**Good Practices**:
- Proper subprocess invocation (shell=False pattern)
- Auth tokens in headers, not URLs
- Well-known, maintained dependencies
- Graceful handling of malformed responses
- Well-implemented tool approval UI with clear display
- Keyboard shortcuts for approve/deny/approve-all

The tool approval flow provides an important security layer. The critical token permission issue should be fixed immediately. Other issues are lower priority due to approval-based mitigation.
