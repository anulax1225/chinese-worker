# vLLM Manager — Auto Model Capabilities Detection

## Problem

vLLM requires three flags set **at engine creation** for thinking and tool calls to work:

```
--reasoning-parser qwen3          → splits <think> into reasoning_content field
--enable-auto-tool-choice         → enables structured tool_calls in responses  
--tool-call-parser hermes         → parses model's tool output into OpenAI format
```

Without them, thinking is raw text in `content` and tool calls are unparsed strings. These flags are model-family-specific and cannot be changed without restarting the engine. The manager must auto-detect them from the model name/config before creating `AsyncEngineArgs`.

---

## New Module: `model_capabilities.py`

Single function called in `ModelManager._load()`, right after preflight, before engine args construction.

### Call Site

```python
# model_manager.py — inside _load()
async def _load(self, model: str) -> None:
    # 1. Preflight (existing)
    # 2. Tokenizer resolution (existing)
    
    # 3. NEW — Detect model capabilities
    caps = detect_capabilities(model, config_json)
    
    # 4. Build engine args with capabilities
    engine_args = AsyncEngineArgs(
        model=model,
        tokenizer=tokenizer or model,
        enable_auto_tool_choice=caps.enable_tool_choice,
        tool_call_parser=caps.tool_call_parser,
        chat_template=caps.chat_template,       # path or None
        # reasoning args are set differently — see below
        **self._base_engine_kwargs(),
    )
    
    # reasoning_parser is not an AsyncEngineArgs field — it's passed
    # to the OpenAI serving layer. Store it for route construction.
    self.reasoning_parser = caps.reasoning_parser
```

---

## Data Structure

```python
@dataclass
class ModelCapabilities:
    # Reasoning
    reasoning_parser: Optional[str] = None        # "qwen3", "deepseek_r1", etc.
    default_enable_thinking: bool = True           # server-wide default
    
    # Tool calling
    enable_tool_choice: bool = False               # --enable-auto-tool-choice
    tool_call_parser: Optional[str] = None         # "hermes", "mistral", etc.
    chat_template: Optional[str] = None            # path to .jinja override or None
    
    # Metadata (for /api/status)
    family: str = "unknown"                        # "qwen3", "llama3", etc.
    supports_thinking: bool = False
    supports_tools: bool = False
```

---

## Detection Logic

Two-pass approach: first try `config.json` fields, then fall back to name pattern matching.

### Pass 1 — Config-based detection

Read `config.json` from the HF cache. Key fields:

```python
architectures = config.get("architectures", [])        # ["Qwen2ForCausalLM"]
model_type = config.get("model_type", "")               # "qwen2"
_name_or_path = config.get("_name_or_path", "")         # "Qwen/Qwen3-8B"
```

The architecture tells us the model family. The `_name_or_path` or the model ID itself tells us the specific variant (thinking vs instruct vs base).

### Pass 2 — Name pattern matching

For quantized models where the architecture matches the base but the name tells us more (e.g. `bartowski/Qwen3-8B-GGUF` still has `Qwen3ForCausalLM`).

---

## Family Map

This is the core lookup. Each entry defines what parsers to use.

```python
MODEL_FAMILIES = [
    # ── Qwen3 ──────────────────────────────────────────────────
    {
        "family": "qwen3",
        "match": lambda arch, name: (
            "Qwen3" in arch 
            or "qwen3" in name.lower()
        ),
        "reasoning_parser": "qwen3",
        "default_enable_thinking": True,
        "tool_call_parser": "hermes",
        "supports_thinking": True,
        "supports_tools": True,
    },
    
    # ── Qwen2.5 ────────────────────────────────────────────────
    {
        "family": "qwen2.5",
        "match": lambda arch, name: (
            "Qwen2" in arch
            and ("qwen2.5" in name.lower() or "qwen2-5" in name.lower())
        ),
        "reasoning_parser": None,
        "tool_call_parser": "hermes",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── QwQ (Qwen reasoning-only) ──────────────────────────────
    {
        "family": "qwq",
        "match": lambda arch, name: "qwq" in name.lower(),
        "reasoning_parser": "deepseek_r1",
        "tool_call_parser": "hermes",
        "supports_thinking": True,
        "supports_tools": True,
    },
    
    # ── DeepSeek R1 / V3 ───────────────────────────────────────
    {
        "family": "deepseek_r1",
        "match": lambda arch, name: (
            "DeepSeek" in arch
            and ("r1" in name.lower() or "v3" in name.lower())
        ),
        "reasoning_parser": "deepseek_r1",
        "tool_call_parser": "hermes",
        "supports_thinking": True,
        "supports_tools": True,
    },
    
    # ── DeepSeek V2 / Coder ────────────────────────────────────
    {
        "family": "deepseek_v2",
        "match": lambda arch, name: "DeepSeek" in arch,
        "reasoning_parser": None,
        "tool_call_parser": "hermes",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── Llama 3.x / 4.x ───────────────────────────────────────
    {
        "family": "llama3",
        "match": lambda arch, name: (
            "Llama" in arch
            and any(v in name.lower() for v in ["llama-3", "llama3", "llama-4", "llama4"])
        ),
        "reasoning_parser": None,
        "tool_call_parser": "llama3_json",
        "chat_template_file": "tool_chat_template_llama3_json.jinja",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── Mistral / Mixtral ──────────────────────────────────────
    {
        "family": "mistral",
        "match": lambda arch, name: (
            any(a in arch for a in ["Mistral", "Mixtral"])
        ),
        "reasoning_parser": None,
        "tool_call_parser": "mistral",
        "chat_template_file": "tool_chat_template_mistral_parallel.jinja",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── Gemma 3 ────────────────────────────────────────────────
    {
        "family": "gemma3",
        "match": lambda arch, name: (
            "Gemma" in arch
            and ("gemma-3" in name.lower() or "gemma3" in name.lower())
        ),
        "reasoning_parser": None,
        "tool_call_parser": "pythonic",
        "chat_template_file": "tool_chat_template_gemma3_pythonic.jinja",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── IBM Granite 3.2+ ───────────────────────────────────────
    {
        "family": "granite",
        "match": lambda arch, name: "Granite" in arch,
        "reasoning_parser": "granite",
        "default_enable_thinking": False,   # opt-in via chat_template_kwargs
        "tool_call_parser": "granite",
        "supports_thinking": True,
        "supports_tools": True,
    },
    
    # ── Hermes (NousResearch) ──────────────────────────────────
    {
        "family": "hermes",
        "match": lambda arch, name: "hermes" in name.lower(),
        "reasoning_parser": None,
        "tool_call_parser": "hermes",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── InternLM ───────────────────────────────────────────────
    {
        "family": "internlm",
        "match": lambda arch, name: "InternLM" in arch,
        "reasoning_parser": None,
        "tool_call_parser": "internlm",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── Hunyuan A13B ───────────────────────────────────────────
    {
        "family": "hunyuan",
        "match": lambda arch, name: "hunyuan" in name.lower(),
        "reasoning_parser": "hunyuan_a13b",
        "tool_call_parser": "hermes",
        "supports_thinking": True,
        "supports_tools": True,
    },
    
    # ── Phi-3 / Phi-4 ─────────────────────────────────────────
    {
        "family": "phi",
        "match": lambda arch, name: "Phi" in arch,
        "reasoning_parser": None,
        "tool_call_parser": "hermes",
        "supports_thinking": False,
        "supports_tools": True,
    },
    
    # ── Fallback: unknown model ────────────────────────────────
    # No parsers — basic chat only. Tools sent in prompt but
    # responses will be raw text.
]
```

**Order matters** — more specific entries (qwen3, qwq) before generic ones (deepseek_v2). First match wins.

---

## Main Function

```python
def detect_capabilities(
    model_id: str,
    config: dict,
    user_overrides: Optional[dict] = None,
) -> ModelCapabilities:
    """
    Auto-detect reasoning parser, tool parser, and chat template
    for a model based on its architecture and name.
    
    user_overrides: explicit settings from env vars that should
    not be overridden (VLLM_REASONING_PARSER, VLLM_TOOL_PARSER, etc.)
    """
    user_overrides = user_overrides or {}
    caps = ModelCapabilities()
    
    architectures = config.get("architectures", [])
    arch_str = " ".join(architectures)
    name_str = config.get("_name_or_path", model_id)
    
    # Find matching family
    for family in MODEL_FAMILIES:
        if family["match"](arch_str, name_str):
            caps.family = family["family"]
            caps.supports_thinking = family.get("supports_thinking", False)
            caps.supports_tools = family.get("supports_tools", False)
            
            # Reasoning parser
            if "VLLM_REASONING_PARSER" not in user_overrides:
                caps.reasoning_parser = family.get("reasoning_parser")
                caps.default_enable_thinking = family.get(
                    "default_enable_thinking", True
                )
            else:
                caps.reasoning_parser = user_overrides["VLLM_REASONING_PARSER"]
            
            # Tool call parser
            if "VLLM_TOOL_PARSER" not in user_overrides:
                caps.tool_call_parser = family.get("tool_call_parser")
                caps.enable_tool_choice = caps.tool_call_parser is not None
            else:
                caps.tool_call_parser = user_overrides["VLLM_TOOL_PARSER"]
                caps.enable_tool_choice = bool(caps.tool_call_parser)
            
            # Chat template override
            if "VLLM_CHAT_TEMPLATE" not in user_overrides:
                template_file = family.get("chat_template_file")
                if template_file:
                    caps.chat_template = _resolve_template_path(template_file)
            else:
                caps.chat_template = user_overrides["VLLM_CHAT_TEMPLATE"]
            
            logger.info(
                f"[caps] {model_id} → family={caps.family} "
                f"reasoning={caps.reasoning_parser} "
                f"tools={caps.tool_call_parser} "
                f"template={'custom' if caps.chat_template else 'default'}"
            )
            return caps
    
    # No match — fallback
    logger.warning(f"[caps] {model_id} → unknown family, no parsers configured")
    return caps
```

---

## Chat Template Resolution

Some models (Mistral, Llama3, Gemma3) need a custom `.jinja` file that vLLM ships in its `examples/` directory. The manager needs to bundle or locate these.

```python
# Bundled templates directory
TEMPLATES_DIR = Path(__file__).parent / "templates"

def _resolve_template_path(filename: str) -> Optional[str]:
    """
    Find a chat template file. Search order:
    1. Bundled in manager's templates/ directory
    2. vLLM's examples/ directory (if installed from source)
    """
    # 1. Our bundled templates
    bundled = TEMPLATES_DIR / filename
    if bundled.exists():
        return str(bundled)
    
    # 2. vLLM's examples directory
    try:
        import vllm
        vllm_root = Path(vllm.__file__).parent.parent
        vllm_template = vllm_root / "examples" / filename
        if vllm_template.exists():
            return str(vllm_template)
    except Exception:
        pass
    
    logger.warning(f"Chat template {filename} not found, using model default")
    return None
```

**Bundle these files** in your Docker image:

```
.sail/vllm/manager/templates/
├── tool_chat_template_llama3_json.jinja
├── tool_chat_template_mistral_parallel.jinja
└── tool_chat_template_gemma3_pythonic.jinja
```

Copy them from the vLLM repo's `examples/` directory at image build time:

```dockerfile
# In Dockerfile
COPY --from=vllm-source /vllm/examples/tool_chat_template_*.jinja /opt/vllm-manager/templates/
```

Models that don't need a template override (Qwen, DeepSeek, Hermes, Granite, Phi) use the one embedded in their `tokenizer_config.json` — vLLM picks it up automatically.

---

## Engine Args Integration

The tricky part: `reasoning_parser` is **not** an `AsyncEngineArgs` field. It's passed to the OpenAI serving layer separately.

```python
# How vLLM's own api_server does it:
#   engine_args = AsyncEngineArgs(...)      ← no reasoning_parser here
#   engine = AsyncLLMEngine.from_engine_args(engine_args)
#   serving_chat = OpenAIServingChat(
#       engine, ...,
#       reasoning_parser=args.reasoning_parser,   ← here
#       enable_auto_tool_choice=args.enable_auto_tool_choice,
#       tool_call_parser=args.tool_call_parser,
#   )

# In your model_manager.py:
class ModelManager:
    def __init__(self, ...):
        self.caps: Optional[ModelCapabilities] = None
    
    async def _load(self, model: str) -> None:
        # ... preflight, tokenizer ...
        
        caps = detect_capabilities(model, config_json, self._user_overrides())
        self.caps = caps
        
        engine_args = AsyncEngineArgs(
            model=model,
            tokenizer=tokenizer or model,
            enable_auto_tool_choice=caps.enable_tool_choice,
            tool_call_parser=caps.tool_call_parser,
            chat_template=caps.chat_template,
            trust_remote_code=True,
            # ... other args ...
        )
        
        self.engine = AsyncLLMEngine.from_engine_args(engine_args)
        
        # Build the serving layer with reasoning parser
        self.serving_chat = OpenAIServingChat(
            self.engine,
            model_config=self.engine.engine.get_model_config(),
            served_model_names=[model],
            reasoning_parser=caps.reasoning_parser,
            enable_auto_tool_choice=caps.enable_tool_choice,
            tool_call_parser=caps.tool_call_parser,
            chat_template=caps.chat_template,
        )
```

Then in the route:

```python
@app.post("/v1/chat/completions")
async def chat_completions(raw_request: Request):
    await model_manager.ensure_loaded(model)
    # Pass directly to vLLM's serving layer — it handles
    # reasoning parsing, tool parsing, SSE streaming, everything.
    response = await model_manager.serving_chat.create_chat_completion(
        raw_request
    )
    return response
```

---

## Config / Env Var Overrides

Users can force specific parsers via env vars, overriding auto-detection.

```python
# config.py additions
class ManagerConfig(BaseSettings):
    # ... existing ...
    
    # Capability overrides (None = auto-detect)
    vllm_reasoning_parser: Optional[str] = None     # VLLM_REASONING_PARSER
    vllm_tool_parser: Optional[str] = None           # VLLM_TOOL_PARSER  
    vllm_chat_template: Optional[str] = None         # VLLM_CHAT_TEMPLATE

def _user_overrides(self) -> dict:
    """Collect explicit user overrides that should not be auto-detected."""
    overrides = {}
    if self.config.vllm_reasoning_parser:
        overrides["VLLM_REASONING_PARSER"] = self.config.vllm_reasoning_parser
    if self.config.vllm_tool_parser:
        overrides["VLLM_TOOL_PARSER"] = self.config.vllm_tool_parser
    if self.config.vllm_chat_template:
        overrides["VLLM_CHAT_TEMPLATE"] = self.config.vllm_chat_template
    return overrides
```

---

## Status Exposure

Expose detected capabilities in `/api/status` so Laravel knows what the loaded model supports:

```python
@app.get("/api/status")
async def status():
    return {
        "state": model_manager.state,
        "model": model_manager.current_model,
        "capabilities": {
            "family": model_manager.caps.family if model_manager.caps else None,
            "supports_thinking": model_manager.caps.supports_thinking if model_manager.caps else False,
            "supports_tools": model_manager.caps.supports_tools if model_manager.caps else False,
            "reasoning_parser": model_manager.caps.reasoning_parser if model_manager.caps else None,
            "tool_call_parser": model_manager.caps.tool_call_parser if model_manager.caps else None,
        } if model_manager.caps else None,
    }
```

Laravel can then use this to decide whether to send tools, whether to expect `reasoning_content` in responses, etc.

---

## File Structure (addition to existing)

```
.sail/vllm/manager/
├── model_capabilities.py        # NEW — detect_capabilities(), MODEL_FAMILIES
├── templates/                   # NEW — bundled .jinja overrides
│   ├── tool_chat_template_llama3_json.jinja
│   ├── tool_chat_template_mistral_parallel.jinja
│   └── tool_chat_template_gemma3_pythonic.jinja
├── main.py
├── model_manager.py             # Modified — calls detect_capabilities()
├── preflight.py
├── tokenizer_resolver.py
├── pull_manager.py
├── cache_manager.py
├── config.py                    # Modified — 3 new env vars
└── ollama_compat.py
```

---

## Updating the Family Map

When a new model family comes out, add one entry to `MODEL_FAMILIES`. That's it — no other code changes. The entry needs:

- `family`: short name for logs/status
- `match`: lambda that receives architecture string + model name
- `reasoning_parser`: string or None
- `tool_call_parser`: string or None  
- `chat_template_file`: filename or omit (use model default)

Check vLLM's docs at `docs.vllm.ai/en/latest/features/tool_calling/` and `docs.vllm.ai/en/latest/features/reasoning_outputs/` for new parsers when upgrading the vLLM version.
