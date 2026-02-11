# vLLM Manager — Output Parsing v2 (Using vLLM's Native Parsers)

**Supersedes**: `vllm-manager-output-parsing-plan.md` (v1 reimplemented all parsers from scratch)

## Problem (unchanged)

`reasoning_parser`, `tool_call_parser`, `enable_auto_tool_choice` are **not** `AsyncEngineArgs` fields. They're `OpenAIServingChat` constructor args. Passing them to `AsyncEngineArgs` → crash.

We don't use `OpenAIServingChat` (our manager builds OpenAI responses itself from raw `AsyncLLMEngine` output). So we need to parse the raw text ourselves.

## v1 Approach (rejected)

Reimplemented every parser from scratch in our own `output_parser.py` — Hermes XML, Llama3 JSON, Mistral `[TOOL_CALLS]`, pythonic, `<think>` regex, Granite markers, streaming state machines, etc.

**Problems with v1**:
- ~500 lines of parsing code that vLLM already has, battle-tested
- Must track every upstream parser bug fix and format change
- Missing edge cases vLLM has already solved (nested tags, partial tokens, Mistral tokenizer quirks)
- New parsers added to vLLM (qwen3_xml, kimi_k2, llama4_pythonic, etc.) require manual porting

## v2 Approach: Use vLLM's Parser Classes Directly

vLLM exposes its parsers as standalone classes with clean APIs. We instantiate them ourselves and call their `extract_*` methods on raw engine output. Zero reimplementation.

---

## Architecture

```
AsyncLLMEngine.generate()
        │
        ▼
  RequestOutput.outputs[0].text       ← raw text from engine
  RequestOutput.outputs[0].token_ids  ← token IDs
        │
        ▼
┌──────────────────────────────────────────────────────┐
│              output_parser.py (thin wrapper)          │
│                                                      │
│  Uses vLLM's actual parser instances:                │
│                                                      │
│  ┌─────────────────────────────────┐                 │
│  │ ReasoningParser instance        │                 │
│  │ (from ReasoningParserManager)   │                 │
│  │                                 │                 │
│  │ .extract_reasoning(text, req)   │                 │
│  │ → (reasoning, content)          │                 │
│  │                                 │                 │
│  │ .extract_reasoning_streaming()  │                 │
│  │ → DeltaMessage                  │                 │
│  └─────────────────────────────────┘                 │
│                                                      │
│  ┌─────────────────────────────────┐                 │
│  │ ToolParser instance             │                 │
│  │ (from ToolParserManager)        │                 │
│  │                                 │                 │
│  │ .extract_tool_calls(text, req)  │                 │
│  │ → ExtractedToolCallInformation  │                 │
│  │                                 │                 │
│  │ .extract_tool_calls_streaming() │                 │
│  │ → DeltaMessage                  │                 │
│  └─────────────────────────────────┘                 │
│                                                      │
│  Returns: ParsedOutput                               │
└──────────────────────────────────────────────────────┘
        │
        ▼
  Build OpenAI ChatCompletion JSON     ← existing response builder
```

**Key difference from v1**: The boxes above are NOT our code — they're vLLM's own parser classes. Our `output_parser.py` is just a thin orchestration layer (~80 lines) that instantiates them and calls their methods in the right order.

---

## vLLM's Parser APIs

### ReasoningParser

```python
from vllm.reasoning import ReasoningParserManager

# Get parser CLASS by name
ParserClass = ReasoningParserManager.get_reasoning_parser("deepseek_r1")

# Instantiate with tokenizer
parser = ParserClass(tokenizer)

# Non-streaming: split full text into (reasoning, content)
reasoning, content = parser.extract_reasoning(model_output, request)
# Returns: (Optional[str], Optional[str])

# Streaming: process delta, returns DeltaMessage with
# .reasoning_content and .content fields routed correctly
delta = parser.extract_reasoning_streaming(
    previous_text,
    current_text,
    delta_text,
    previous_token_ids,
    current_token_ids,
    delta_token_ids,
)
# Returns: Optional[DeltaMessage]
```

**Available reasoning parsers** (registered in vLLM):
`deepseek_r1`, `deepseek_v3`, `granite`, `qwen3`, `hunyuan_a13b`, `mistral`, `glm45`, `holo2`, `kimi_k2`, `minimax_m2`, `olmo3`, `ernie45`, `gptoss`

### ToolParser

```python
from vllm.tool_parsers import ToolParserManager

# Get parser CLASS by name
ParserClass = ToolParserManager.get_tool_parser("hermes")

# Instantiate with tokenizer
parser = ParserClass(tokenizer)

# Non-streaming: extract tool calls from full text
result = parser.extract_tool_calls(model_output, request)
# Returns: ExtractedToolCallInformation
#   .tools_called: bool
#   .tool_calls: list[ToolCall]     ← OpenAI-format ToolCall objects
#   .content: Optional[str]         ← remaining text after removing tool markup

# Streaming: process delta
delta = parser.extract_tool_calls_streaming(
    previous_text,
    current_text,
    delta_text,
    previous_token_ids,
    current_token_ids,
    delta_token_ids,
    request,
)
# Returns: Optional[DeltaMessage] with .tool_calls field

# Some parsers also adjust the request (e.g., disable skip_special_tokens)
request = parser.adjust_request(request)
```

**Available tool parsers** (registered in vLLM):
`hermes`, `mistral`, `llama3_json`, `llama4_json`, `llama4_pythonic`, `pythonic`, `granite`, `granite-20b-fc`, `internlm`, `jamba`, `phi4_mini_json`, `qwen3_coder`, `xlam`, `kimi_k2`, `minimax`, `deepseek_v3`

### ExtractedToolCallInformation

```python
# From vllm.entrypoints.openai.engine.protocol
@dataclass
class ExtractedToolCallInformation:
    tools_called: bool                # True if any tool calls found
    tool_calls: list[ToolCall]        # Already OpenAI-compatible ToolCall objects
    content: Optional[str]            # Remaining content after tool call extraction
```

The `ToolCall` objects inside already have `.id`, `.function.name`, `.function.arguments` — ready to serialize directly into the OpenAI response. No conversion needed.

### DeltaMessage

```python
# From vllm.entrypoints.openai.engine.protocol
class DeltaMessage:
    role: Optional[str]
    content: Optional[str]
    reasoning_content: Optional[str]
    tool_calls: Optional[list[DeltaToolCall]]
```

Again, already OpenAI-compatible. We just pass these fields through.

---

## Module: `output_parser.py`

Thin orchestration — instantiates vLLM parsers once per model load, provides two methods.

```python
"""
output_parser.py — Thin wrapper around vLLM's native parsers.

Handles reasoning extraction and tool call parsing on raw engine output
without using OpenAIServingChat.
"""

from dataclasses import dataclass, field
from typing import Optional, Sequence

from vllm.reasoning import ReasoningParser, ReasoningParserManager
from vllm.tool_parsers import ToolParser, ToolParserManager


@dataclass
class ParsedOutput:
    """Result of parsing raw model output."""
    reasoning_content: Optional[str] = None
    content: Optional[str] = None
    tool_calls: list = field(default_factory=list)  # vLLM ToolCall objects
    tools_called: bool = False
    finish_reason: str = "stop"


class OutputParser:
    """
    Wraps vLLM's ReasoningParser and ToolParser instances.
    Created once per model load, reused for all requests.
    """

    def __init__(
        self,
        tokenizer,
        reasoning_parser_name: Optional[str] = None,
        tool_parser_name: Optional[str] = None,
    ):
        self.reasoning_parser: Optional[ReasoningParser] = None
        self.tool_parser: Optional[ToolParser] = None

        if reasoning_parser_name:
            cls = ReasoningParserManager.get_reasoning_parser(
                reasoning_parser_name
            )
            self.reasoning_parser = cls(tokenizer)

        if tool_parser_name:
            cls = ToolParserManager.get_tool_parser(tool_parser_name)
            self.tool_parser = cls(tokenizer)

    @property
    def has_reasoning(self) -> bool:
        return self.reasoning_parser is not None

    @property
    def has_tools(self) -> bool:
        return self.tool_parser is not None

    def parse(
        self,
        model_output: str,
        request=None,
        finish_reason: str = "stop",
    ) -> ParsedOutput:
        """
        Non-streaming: parse complete model output.
        
        Pipeline:
        1. Extract reasoning (thinking) → splits into reasoning + content
        2. Extract tool calls from content → splits into tool_calls + remaining
        """
        result = ParsedOutput(finish_reason=finish_reason)

        # Step 1: Reasoning extraction
        if self.reasoning_parser and request:
            reasoning, content = self.reasoning_parser.extract_reasoning(
                model_output, request
            )
            result.reasoning_content = reasoning
            working_text = content or ""
        else:
            working_text = model_output

        # Step 2: Tool call extraction (from content only, not reasoning)
        if self.tool_parser and request:
            tool_result = self.tool_parser.extract_tool_calls(
                working_text, request
            )
            result.tools_called = tool_result.tools_called
            result.tool_calls = tool_result.tool_calls
            result.content = tool_result.content  # text minus tool markup
        else:
            result.content = working_text

        # Step 3: Adjust finish_reason
        if result.tools_called:
            result.finish_reason = "tool_calls"

        return result

    def parse_streaming(
        self,
        previous_text: str,
        current_text: str,
        delta_text: str,
        previous_token_ids: Sequence[int],
        current_token_ids: Sequence[int],
        delta_token_ids: Sequence[int],
        request=None,
    ):
        """
        Streaming: parse a single delta.
        Returns vLLM's DeltaMessage (already OpenAI-compatible).
        
        Order matters: reasoning first, then tool calls on content.
        """
        # Step 1: Reasoning extraction on the delta
        if self.reasoning_parser:
            delta_msg = self.reasoning_parser.extract_reasoning_streaming(
                previous_text,
                current_text,
                delta_text,
                previous_token_ids,
                current_token_ids,
                delta_token_ids,
            )
            if delta_msg is not None:
                # If reasoning parser consumed it, check if content part
                # needs tool parsing
                if (self.tool_parser
                        and delta_msg.content is not None
                        and request is not None):
                    tool_delta = self.tool_parser.extract_tool_calls_streaming(
                        previous_text,
                        current_text,
                        delta_text,
                        previous_token_ids,
                        current_token_ids,
                        delta_token_ids,
                        request,
                    )
                    if tool_delta is not None and tool_delta.tool_calls:
                        delta_msg.tool_calls = tool_delta.tool_calls
                        delta_msg.content = None
                return delta_msg

        # Step 2: No reasoning parser — go straight to tool parsing
        if self.tool_parser and request:
            delta_msg = self.tool_parser.extract_tool_calls_streaming(
                previous_text,
                current_text,
                delta_text,
                previous_token_ids,
                current_token_ids,
                delta_token_ids,
                request,
            )
            return delta_msg

        # Step 3: No parsers at all — pass through as content
        return None  # caller uses delta_text as-is
```

That's ~120 lines total, including docstrings. Compare to ~500+ in v1.

---

## Integration with `model_manager.py`

### Engine Args — Clean

```python
# Only standard engine-level args. No tool/reasoning args.
engine_args = AsyncEngineArgs(
    model=model,
    tokenizer=resolved_tokenizer or model,
    dtype=self.config.vllm_dtype,
    max_model_len=self.config.vllm_max_model_len,
    gpu_memory_utilization=self.config.gpu_memory_utilization,
    trust_remote_code=True,
    enforce_eager=self.config.enforce_eager,
    chat_template=caps.chat_template,  # needed for INPUT formatting
)
```

### Creating the OutputParser

```python
class ModelManager:
    def __init__(self):
        self.engine: Optional[AsyncLLMEngine] = None
        self.caps: Optional[ModelCapabilities] = None
        self.parser: Optional[OutputParser] = None  # NEW
        self.tokenizer = None

    async def _load(self, model: str) -> None:
        # 1. Preflight
        # 2. Tokenizer resolution
        # 3. Detect capabilities
        self.caps = detect_capabilities(model, config_json)

        # 4. Build engine (standard args only)
        engine_args = AsyncEngineArgs(
            model=model,
            chat_template=self.caps.chat_template,
            # ... standard args ...
        )
        self.engine = AsyncLLMEngine.from_engine_args(engine_args)

        # 5. Load tokenizer for parser instantiation
        self.tokenizer = AutoTokenizer.from_pretrained(
            resolved_tokenizer or model,
            trust_remote_code=True,
        )

        # 6. NEW — Create output parser with vLLM's native parsers
        self.parser = OutputParser(
            tokenizer=self.tokenizer,
            reasoning_parser_name=self.caps.reasoning_parser,
            tool_parser_name=self.caps.tool_call_parser,
        )
```

### Usage in Route Handlers

#### Non-streaming

```python
# POST /v1/chat/completions (stream=false)
async def chat_completion(request):
    # ... build prompt, sampling params ...
    
    # Generate
    final_output = None
    async for output in engine.generate(prompt, params, request_id):
        final_output = output
    
    raw_text = final_output.outputs[0].text
    finish_reason = final_output.outputs[0].finish_reason or "stop"
    
    # Parse using vLLM's native parsers
    parsed = manager.parser.parse(
        model_output=raw_text,
        request=openai_request,  # ChatCompletionRequest object
        finish_reason=finish_reason,
    )
    
    # Build response — fields come back already in OpenAI format
    message = {"role": "assistant", "content": parsed.content}
    
    if parsed.reasoning_content is not None:
        message["reasoning_content"] = parsed.reasoning_content
    
    if parsed.tools_called:
        # tool_calls are already vLLM ToolCall objects — serialize directly
        message["tool_calls"] = [
            {
                "id": tc.id,
                "type": "function",
                "function": {
                    "name": tc.function.name,
                    "arguments": tc.function.arguments,
                }
            }
            for tc in parsed.tool_calls
        ]
        message["content"] = parsed.content  # may be None
    
    return {
        "id": f"chatcmpl-{uuid4().hex}",
        "object": "chat.completion",
        "model": model_name,
        "choices": [{
            "index": 0,
            "message": message,
            "finish_reason": parsed.finish_reason,
        }],
        "usage": { ... }
    }
```

#### Streaming

```python
# POST /v1/chat/completions (stream=true)
async def chat_completion_stream(request):
    previous_text = ""
    previous_token_ids = []
    
    async for output in engine.generate(prompt, params, request_id):
        current_text = output.outputs[0].text
        current_token_ids = list(output.outputs[0].token_ids)
        delta_text = current_text[len(previous_text):]
        delta_token_ids = current_token_ids[len(previous_token_ids):]
        
        is_finished = output.finished
        
        if delta_text or is_finished:
            # Use vLLM's streaming parsers
            delta_msg = manager.parser.parse_streaming(
                previous_text=previous_text,
                current_text=current_text,
                delta_text=delta_text,
                previous_token_ids=previous_token_ids,
                current_token_ids=current_token_ids,
                delta_token_ids=delta_token_ids,
                request=openai_request,
            )
            
            # Build SSE chunk from DeltaMessage
            chunk_delta = {"role": "assistant"}
            
            if delta_msg is not None:
                if delta_msg.reasoning_content is not None:
                    chunk_delta["reasoning_content"] = delta_msg.reasoning_content
                if delta_msg.content is not None:
                    chunk_delta["content"] = delta_msg.content
                if delta_msg.tool_calls:
                    chunk_delta["tool_calls"] = [
                        tc.model_dump() for tc in delta_msg.tool_calls
                    ]
            else:
                # No parser active — raw delta is content
                chunk_delta["content"] = delta_text
            
            finish_reason = None
            if is_finished:
                finish_reason = output.outputs[0].finish_reason or "stop"
                # Check if tools were called across the full output
                if manager.parser.has_tools:
                    full_parsed = manager.parser.parse(
                        current_text, openai_request
                    )
                    if full_parsed.tools_called:
                        finish_reason = "tool_calls"
            
            yield sse_chunk({
                "choices": [{
                    "index": 0,
                    "delta": chunk_delta,
                    "finish_reason": finish_reason,
                }]
            })
        
        previous_text = current_text
        previous_token_ids = current_token_ids
```

---

## Streaming — Reasoning + Tool Calls Together

The tricky case is models like Qwen3 or QwQ that produce both `<think>` AND `<tool_call>` in the same response:

```
<think>I need to check the weather in Paris.</think>
<tool_call>
{"name": "get_weather", "arguments": {"city": "Paris"}}
</tool_call>
```

vLLM handles this correctly in its parsers: the reasoning parser extracts thinking first, then tool parsing runs on the remaining content. The order is baked into `OpenAIServingChat`, and we replicate it in `OutputParser.parse()`:

1. `reasoning_parser.extract_reasoning()` → `(thinking_text, content_text)`
2. `tool_parser.extract_tool_calls(content_text)` → tool calls from content only

This matches vLLM's documented behavior: "tool calling only parses functions from the content field, not from the reasoning."

---

## Streaming — Practical Consideration

For streaming reasoning + tool calls, there's a subtlety. vLLM's `ReasoningParser.extract_reasoning_streaming()` and `ToolParser.extract_tool_calls_streaming()` each maintain **internal state** via instance attributes. They're designed to be called once per token, across the full generation.

The challenge: if reasoning parser consumes tokens up to `</think>`, the tool parser needs to see only the post-thinking tokens. But both parsers receive the same `(previous_text, current_text, delta_text)` — the full cumulative output including thinking.

**How vLLM handles this internally**: In `OpenAIServingChat`, reasoning is parsed first. If reasoning is active, content deltas are suppressed until thinking ends. Then tool parsing starts on the content-only portion.

**Our approach**: For the streaming path with both parsers active:

```python
def parse_streaming(self, ...):
    if self.reasoning_parser:
        delta_msg = self.reasoning_parser.extract_reasoning_streaming(...)
        
        if delta_msg is None:
            return None  # still buffering
        
        # Reasoning parser splits into reasoning_content / content
        # If content is coming through, pass it to tool parser
        if delta_msg.content is not None and self.tool_parser:
            # Tool parser sees content-only portion
            # In practice, for non-streaming tool calls, we do a final
            # parse at the end (see streaming handler above)
            pass
        
        return delta_msg
    
    if self.tool_parser:
        return self.tool_parser.extract_tool_calls_streaming(...)
    
    return None
```

**Simplification**: For the streaming case with both reasoning + tools, we stream reasoning and content in real-time via the reasoning parser, and do a **final non-streaming tool parse** on the complete content at `finish_reason` time. This is the simplest correct approach and matches what most clients expect (tool calls appear in the final chunk).

---

## Import Compatibility

vLLM's module structure has changed across versions. Handle both:

```python
# output_parser.py — import compatibility

try:
    # vLLM >= 0.9.x (current)
    from vllm.reasoning import ReasoningParserManager
    from vllm.tool_parsers import ToolParserManager
except ImportError:
    # vLLM 0.7.x - 0.8.x (older)
    from vllm.entrypoints.openai.reasoning_parsers import ReasoningParserManager
    from vllm.entrypoints.openai.tool_parsers import ToolParserManager

try:
    # vLLM >= 0.10.x (method renamed)
    # .extract_reasoning() replaces .extract_reasoning_content()
    _REASONING_METHOD = "extract_reasoning"
    _REASONING_STREAMING_METHOD = "extract_reasoning_streaming"
except AttributeError:
    _REASONING_METHOD = "extract_reasoning_content"
    _REASONING_STREAMING_METHOD = "extract_reasoning_content_streaming"
```

In `OutputParser.parse()`, use `getattr()`:

```python
extract_fn = getattr(self.reasoning_parser, _REASONING_METHOD)
reasoning, content = extract_fn(model_output, request)
```

---

## ChatCompletionRequest — Minimal Construction

vLLM's parsers take a `ChatCompletionRequest` argument. We need to construct one even though we're not using `OpenAIServingChat`. Most parser methods only read a few fields from it (mainly to check `tool_choice`). We can build a minimal one:

```python
from vllm.entrypoints.openai.chat_completion.protocol import ChatCompletionRequest

def make_parser_request(
    model: str,
    messages: list,
    tools: Optional[list] = None,
    tool_choice: str = "auto",
) -> ChatCompletionRequest:
    """Build a minimal ChatCompletionRequest for parser consumption."""
    return ChatCompletionRequest(
        model=model,
        messages=messages,
        tools=tools,
        tool_choice=tool_choice if tools else "none",
    )
```

This is passed to `parser.parse(model_output, request=req)`.

If constructing `ChatCompletionRequest` proves too heavy (it's a Pydantic model with many fields), we can instead:
1. Pass `request=None` — some parsers handle this gracefully
2. Create a lightweight duck-typed object with just the fields parsers read
3. Use the actual incoming request dict, converted

**Recommendation**: Try passing the actual request dict from the API first. If the Pydantic validation is too strict, fall back to a minimal construction.

---

## What `model_capabilities.py` Changes

Minimal changes from the previous plan:

```python
@dataclass
class ModelCapabilities:
    # Reasoning (used to instantiate ReasoningParser)
    reasoning_parser: Optional[str] = None       # "qwen3", "deepseek_r1", etc.
    default_enable_thinking: bool = True

    # Tool calling (used to instantiate ToolParser)
    tool_call_parser: Optional[str] = None       # "hermes", "mistral", etc.

    # Chat template (used by AsyncEngineArgs for INPUT formatting)
    chat_template: Optional[str] = None

    # Metadata
    family: str = "unknown"
    supports_thinking: bool = False
    supports_tools: bool = False
```

Same as before. The detection logic (family map, config.json reading) is unchanged.

---

## `adjust_request` Hook

Some tool parsers need to modify the request before generation. For example, the Hermes parser may set `skip_special_tokens=False` so that `<tool_call>` tokens aren't stripped. Call this before generating:

```python
# In chat_completion handler, before calling engine.generate():
if manager.parser.tool_parser:
    openai_request = manager.parser.tool_parser.adjust_request(openai_request)
```

This is a detail v1 missed entirely. Using vLLM's parsers means we get this for free.

---

## File Structure

```
.sail/vllm/manager/
├── main.py                    # routes — uses OutputParser
├── model_manager.py           # creates OutputParser at model load
├── output_parser.py           # NEW — ~120 lines, wraps vLLM parsers
├── model_capabilities.py      # detection — unchanged
├── preflight.py               # validation — unchanged
├── tokenizer_resolver.py      # unchanged
├── pull_manager.py            # unchanged
├── cache_manager.py           # unchanged
├── config.py                  # unchanged
├── ollama_compat.py           # unchanged
└── templates/                 # .jinja files for INPUT formatting
    ├── tool_chat_template_llama3_json.jinja
    ├── tool_chat_template_mistral_parallel.jinja
    └── tool_chat_template_gemma3_pythonic.jinja
```

---

## Comparison: v1 vs v2

| Aspect | v1 (custom parsers) | v2 (vLLM native parsers) |
|---|---|---|
| **Lines of parsing code** | ~500+ | ~120 (wrapper only) |
| **Parser implementations** | Our own regex/JSON | vLLM's battle-tested classes |
| **Supported formats** | 4 (hermes, llama3, mistral, pythonic) | 20+ (all vLLM has) |
| **Streaming reasoning** | Custom state machine | `extract_reasoning_streaming()` |
| **Streaming tool calls** | Custom buffering | `extract_tool_calls_streaming()` |
| **Edge cases** | Must discover and fix ourselves | Handled by vLLM upstream |
| **New model support** | Must port each new parser | Automatic via vLLM updates |
| **`adjust_request` hook** | Missing | Included |
| **Token-ID-based parsing** | Not implemented | Used by vLLM (faster, more reliable) |
| **Nested `<think>` tags** | Fragile regex | Handled by vLLM's token-ID approach |
| **Dependency** | None (standalone) | `vllm.reasoning`, `vllm.tool_parsers` |
| **Maintenance** | High | Near-zero |

---

## Risks and Mitigations

### Risk 1: Import path changes across vLLM versions
**Mitigation**: Compatibility imports with try/except (see section above). Pin vLLM version in Docker image.

### Risk 2: `ChatCompletionRequest` construction overhead
**Mitigation**: Build minimal instance. Most parsers only read `tool_choice` and `tools` fields. If Pydantic validation is heavy, cache the request object per-request.

### Risk 3: Parser internal state in streaming
**Mitigation**: Both `ReasoningParser` and `ToolParser` are instantiated per-model, but streaming methods are stateful per-request (they track what's been parsed). For concurrent requests, each request needs its own parser instance OR we need to ensure the streaming methods are re-entrant. 

**Solution**: Create a new parser instance per streaming request:

```python
# In streaming handler
request_parser = OutputParser(
    tokenizer=manager.tokenizer,
    reasoning_parser_name=manager.caps.reasoning_parser,
    tool_parser_name=manager.caps.tool_call_parser,
)
```

Parser instantiation is lightweight (just stores tokenizer + looks up token IDs). This is safe for concurrent requests.

For non-streaming, parsers are stateless (`extract_reasoning` and `extract_tool_calls` are effectively static), so the shared instance is fine.

### Risk 4: vLLM parser expects fields we don't provide
**Mitigation**: Test with `request=None` first. If any parser crashes, wrap in try/except and fall back to raw text (no parsing). Log a warning so user knows to report it.

---

## Implementation Order

1. **`output_parser.py`** — The wrapper module (~120 lines)
2. **Update `model_manager.py`** — Create `OutputParser` at model load
3. **Update `main.py` non-streaming** — Call `parser.parse()` in response builder
4. **Update `main.py` streaming** — Call `parser.parse_streaming()` in SSE loop
5. **Test with Qwen3** — Reasoning + tool calls
6. **Test with Llama3** — Tool calls only (no reasoning)
7. **Test with DeepSeek R1** — Reasoning only (no tools)
