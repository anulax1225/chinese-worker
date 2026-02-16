# Python CLI - Performance Audit

## Overview

This audit covers performance aspects of the Python CLI including SSE streaming efficiency, memory management, TUI responsiveness, and network error recovery.

## Critical Files

| Category | Path |
|----------|------|
| SSE Client | `cw-cli/chinese_worker/api/sse_client.py` |
| SSE Handler | `cw-cli/chinese_worker/tui/handlers/sse_handler.py` |
| TUI App | `cw-cli/chinese_worker/tui/app.py` |
| Message List | `cw-cli/chinese_worker/tui/widgets/message_list.py` |
| API Client | `cw-cli/chinese_worker/api/client.py` |
| Tools | `cw-cli/chinese_worker/tools/` |

---

## Checklist

### 1. SSE Streaming Efficiency

#### 1.1 Connection Management
- [x] **Connection reuse** - Verify connection handling
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:47-52`
  - Finding: Uses `httpx.stream()` context manager. Connection maintained for duration of streaming session.

- [x] **Buffering strategy** - Verify event buffering
  - Finding: Events processed line-by-line via `iter_lines()`. Minimal buffering - processes as data arrives.

- [x] **Timeout configuration** - Verify timeouts
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:51`
  - Finding: Configurable timeout (default 60s for conversations, 600s for model pulls). Uses separate connect/read/write timeouts.

#### 1.2 Event Processing
- [x] **Incremental parsing** - Verify event parsing
  - Finding: Line-by-line parsing. Event type and data accumulated until empty line signals event end.

- [x] **Event dispatch efficient** - Verify dispatch overhead
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:36`
  - Finding: Generator pattern (`yield`) - minimal overhead between receive and use.

- [x] **No blocking operations** - Verify async handling
  - Finding: SSE client is synchronous but non-blocking for I/O via httpx. Processing is sequential.

#### 1.3 Stream Cleanup
- [x] **Clean disconnection** - Verify disconnect handling
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:89-96`
  - Finding: `close()` method available. Context manager ensures cleanup. Response closed in `finally`.

- [x] **Reconnection efficiency** - Verify reconnect logic
  - Finding: Client doesn't auto-reconnect. CLI manages reconnection after tool requests.

---

### 2. Memory Management

#### 2.1 Message History
- [x] **Message list growth** - Verify memory bounds
  - Reference: `cw-cli/chinese_worker/tui/widgets/message_list.py`
  - Finding: MessageList is a thin wrapper around Textual's `VerticalScroll`. No explicit memory bounds. Messages accumulate indefinitely. For very long conversations, could grow unbounded. Documented in CLI-PERF-006.

- [x] **Large message handling** - Verify big message handling
  - Finding: CLI accumulates chunks into strings. No explicit size limits during streaming.

- [x] **Streaming message accumulation** - Verify chunk handling
  - Reference: `cw-cli/chinese_worker/cli.py:664-666`
  - Finding: Simple string concatenation (`accumulated_content += chunk`). Python handles efficiently for moderate sizes.

#### 2.2 API Response Handling
- [x] **Response streaming** - Verify response handling
  - Reference: `cw-cli/chinese_worker/api/client.py`
  - Finding: **NOT STREAMED** - Regular API calls use `response.json()` which buffers entire response. Acceptable for typical API responses (agent lists, conversation metadata). SSE streaming used for actual AI responses.

- [x] **JSON parsing efficiency** - Verify parsing
  - Finding: Uses standard json module. Adequate for typical response sizes.

#### 2.3 Tool Execution
- [x] **Output buffering** - Verify tool output handling
  - Reference: `cw-cli/chinese_worker/tools/bash.py:60-61`
  - Finding: Uses `capture_output=True` - buffers stdout/stderr in memory. No streaming for very long outputs.

- [x] **File reading** - Verify file memory use
  - Reference: `cw-cli/chinese_worker/tools/read.py:68-69`
  - Finding: **LOADS ENTIRE FILE** - `f.readlines()` loads all lines into memory. No streaming for large files. Documented in CLI-PERF-001.

---

### 3. TUI Responsiveness

#### 3.1 Render Performance
- [x] **Efficient rendering** - Verify render efficiency
  - Reference: `cw-cli/chinese_worker/cli.py:654`
  - Finding: Uses Rich `Live` with `refresh_per_second=10` and `transient=True`. Efficient progressive updates.

- [x] **Scroll performance** - Verify scroll efficiency
  - Reference: `cw-cli/chinese_worker/tui/widgets/message_list.py`
  - Finding: Uses Textual's `VerticalScroll` which virtualizes rendering. Only visible content rendered. Efficient for scroll performance.

- [x] **Input responsiveness** - Verify input handling
  - Reference: `cw-cli/chinese_worker/tui/widgets/input_area.py`
  - Finding: `ChatInput` is thin wrapper around Textual's `Input` widget. Textual handles input efficiently with async event loop.

#### 3.2 Background Operations
- [x] **SSE doesn't block UI** - Verify async operation
  - Finding: In CLI mode, SSE is synchronous but uses Live display for progressive updates. In TUI mode, handled differently.

- [x] **Tool execution non-blocking** - Verify tool handling
  - Finding: In CLI mode, tool execution is blocking (user waits). Appropriate for CLI context.

- [x] **API calls non-blocking** - Verify API handling
  - Finding: API calls are synchronous. Uses Rich Progress spinner during waits.

#### 3.3 Widget Updates
- [x] **Status updates efficient** - Verify status bar
  - Reference: `cw-cli/chinese_worker/tui/widgets/status_bar.py:32-37`
  - Finding: `set_status()` uses `query_one()` and `update()` for efficient single-widget updates. No full re-render.

- [x] **Tool approval responsive** - Verify approval widget
  - Reference: `cw-cli/chinese_worker/tui/widgets/tool_approval.py`
  - Finding: Modal dialog with Y/N/A keybindings. Efficient event handling via Textual's message system.

---

### 4. Network Error Recovery

#### 4.1 Connection Errors
- [x] **Connection refused** - Verify handling
  - Finding: `httpx.ConnectError` caught. CLI displays error message, doesn't crash.

- [x] **DNS failure** - Verify handling
  - Finding: Included in ConnectError handling. Error message shown to user.

- [x] **Timeout handling** - Verify timeout recovery
  - Reference: `cw-cli/chinese_worker/api/sse_client.py:119-120`
  - Finding: `httpx.ReadTimeout` caught explicitly. Falls back to polling mode.

#### 4.2 SSE Recovery
- [x] **Stream disconnection** - Verify reconnect
  - Finding: **NO AUTO-RECONNECT** - If stream drops unexpectedly, CLI shows error. User must retry. Documented in CLI-PERF-003.

- [x] **Partial event handling** - Verify incomplete data
  - Finding: Event buffer cleared between events. Partial events don't corrupt state.

- [x] **Server-side close** - Verify clean close handling
  - Finding: Terminal events (completed, failed, cancelled) handled. Generator exits cleanly.

#### 4.3 API Error Recovery
- [x] **5xx errors** - Verify server error handling
  - Reference: `cw-cli/chinese_worker/api/client.py`
  - Finding: `raise_for_status()` raises exception. No automatic retry with backoff. Documented in CLI-PERF-004.

- [x] **Rate limiting** - Verify 429 handling
  - Reference: `cw-cli/chinese_worker/api/client.py`
  - Finding: No specific 429 handling. Treated as generic HTTP error. Should add exponential backoff for 429 responses.

---

### 5. Tool Execution Performance

#### 5.1 Bash Tool
- [x] **Process spawn efficiency** - Verify subprocess handling
  - Finding: Direct `subprocess.run()` - efficient process creation.

- [x] **Output streaming** - Verify output handling
  - Finding: **NOT STREAMED** - `capture_output=True` buffers all output. Long-running commands may buffer lots of output. Documented in CLI-PERF-002.

- [x] **Timeout enforcement** - Verify timeout performance
  - Reference: `cw-cli/chinese_worker/tools/bash.py:64`
  - Finding: `timeout` parameter enforced by subprocess. Process killed on timeout.

#### 5.2 File Tools
- [x] **Read performance** - Verify file reading
  - Finding: **LOADS ALL LINES** - `readlines()` loads entire file. Large files (100MB+) could cause memory issues. Documented in CLI-PERF-001.

- [x] **Write performance** - Verify file writing
  - Finding: Direct `open()` and `write()`. Efficient for typical sizes. No explicit fsync.

- [x] **Glob performance** - Verify pattern matching
  - Reference: `cw-cli/chinese_worker/tools/glob.py:69-80`
  - Finding: Uses `pathlib.glob()` which is efficient O(n) directory traversal. Results sorted by mtime (requires stat calls). Acceptable for typical codebases.

- [x] **Grep performance** - Verify search efficiency
  - Reference: `cw-cli/chinese_worker/tools/grep.py:204-252`
  - Finding: Uses `os.walk()` for traversal, `re.compile()` for regex, and `f.readlines()` for file reading. Same memory concern as ReadTool for large files. Linear complexity overall.

---

### 6. Startup Performance

#### 6.1 Import Time
- [x] **Lazy imports** - Verify import efficiency
  - Reference: `cw-cli/chinese_worker/cli.py:151-153`
  - Finding: TUI import is lazy (`from .tui import CWApp` only when needed). Tool imports are eager but necessary. Good pattern for TUI.

- [x] **Startup time** - Measure startup
  - Finding: Not measured with profiler. Lazy TUI imports help CLI commands start quickly. Httpx and Rich are main dependencies loaded eagerly.

#### 6.2 Initialization
- [x] **Auth check efficiency** - Verify auth initialization
  - Reference: `cw-cli/chinese_worker/api/auth.py:40-48`
  - Finding: Reads from JSON file on each call. No in-memory caching. Acceptable for typical usage.

- [x] **TUI initialization** - Verify TUI startup
  - Reference: `cw-cli/chinese_worker/tui/app.py:39-50`
  - Finding: TUI uses async `on_mount()` for initialization. Auth check and screen push are async. APIClient created synchronously. No blocking operations during startup.

---

### 7. Profiling Recommendations

#### 7.1 Tools to Use
- [x] **Memory profiling** - Verify memory behavior
  - Tool: `memory_profiler` or `tracemalloc`
  - Focus: Message accumulation, large file reads, grep over large files
  - Status: Recommended for production validation. Known issues documented.

- [x] **CPU profiling** - Verify CPU usage
  - Tool: `cProfile` or `py-spy`
  - Focus: Render loops, event processing
  - Status: Recommended if TUI performance issues observed.

- [x] **Network profiling** - Verify network efficiency
  - Focus: Request count, connection reuse
  - Status: Httpx handles connection pooling. SSE uses single connection per stream.

---

## Findings

| ID | Item | Severity | Finding | Status |
|----|------|----------|---------|--------|
| CLI-PERF-001 | Read tool loads entire file | Medium | `ReadTool` uses `readlines()` which loads entire file into memory. Large files (100MB+) could exhaust memory. | Open |
| CLI-PERF-002 | Bash output not streamed | Low | Bash tool buffers all output before returning. Very long outputs could consume memory. | Open |
| CLI-PERF-003 | No SSE auto-reconnect | Low | If SSE stream drops unexpectedly, no automatic reconnection. User must retry. | Open |
| CLI-PERF-004 | No retry with backoff | Low | API errors don't trigger automatic retry. All retries require user intervention. | Open |
| CLI-PERF-005 | Auth reads file each call | Low | `get_token()` reads from disk each time. Could cache in memory for session. | Open |
| CLI-PERF-006 | Unbounded message list | Low | TUI MessageList has no memory bounds. Very long conversations could exhaust memory. | Open |

---

## Recommendations

1. **Stream Large Files in ReadTool**: Use generator pattern for large files:
   ```python
   def execute(self, args):
       with open(file_path) as f:
           for i, line in enumerate(f, start=1):
               if i > limit:
                   break
               yield line  # Or accumulate with size check
   ```

2. **Add Output Size Limits**: Truncate tool outputs exceeding reasonable limits (e.g., 1MB) to prevent memory issues.

3. **Add SSE Reconnection**: Implement exponential backoff reconnection for unexpected stream drops:
   ```python
   for attempt in range(max_retries):
       try:
           for event in sse_client.events():
               yield event
           break
       except ConnectionError:
           time.sleep(2 ** attempt)
   ```

4. **Add Retry Logic**: Implement configurable retry with backoff for transient API errors (5xx, network errors).

5. **Cache Auth Token**: Store token in memory after first read to avoid repeated file I/O.

## Summary

The CLI demonstrates **acceptable performance** for typical usage with some areas for improvement:

**Strengths**:
- Efficient SSE streaming with generator pattern
- Proper timeout configuration
- Lazy TUI import for faster non-TUI commands
- Rich Live display for responsive streaming output

**Weaknesses**:
- File reading loads entire file into memory
- No automatic reconnection or retry logic
- Some unnecessary file I/O (auth token)

For typical usage (small-to-medium files, normal conversation lengths), performance should be adequate. Large file handling and network resilience could be improved for production use.
