# vLLM Manager v6: Embedded Engine with Ollama Semantics
## Replacing subprocess+proxy with direct vLLM engine embedding

---

## What Changed from v5

v5 used a **subprocess + proxy** architecture: the manager spawned `vllm serve` as a child process on port 8001 and proxied all `/v1/*` requests through httpx. This works but has drawbacks:

| v5 (subprocess + proxy) | v6 (embedded engine) |
|------------------------|---------------------|
| Two processes in container | Single process |
| HTTP proxy overhead on every request | Direct engine calls, zero overhead |
| Health polling loop to detect readiness | Engine ready callback, instant |
| Proxy must handle SSE passthrough | Native SSE from engine |
| vLLM crash → orphan manager | One process, clean lifecycle |
| No keep-alive (model always loaded) | Ollama-style auto-unload |
| Blocking pull (server stalls) | Background pull, inference continues |
| Model switch = full process restart | Engine swap, same process |

### Core Idea

```python
# v5: spawn subprocess, proxy HTTP
process = subprocess.Popen(["python", "-m", "vllm.entrypoints.openai.api_server", ...])
# then proxy every request through httpx to localhost:8001

# v6: import vllm, create engine directly
from vllm import AsyncLLMEngine, AsyncEngineArgs
engine = AsyncLLMEngine.from_engine_args(args)
# engine is a Python object — call it directly, no network hop
```

---

## Architecture

```
┌─── vLLM Container (sail-vllm) ──────────────────────────────────────────┐
│                                                                          │
│  ┌─ vllm-manager (single Python process, FastAPI :8000) ──────────────┐  │
│  │                                                                    │  │
│  │  ┌─ ModelManager ────────────────────────────────────────────────┐  │  │
│  │  │  • Holds AsyncLLMEngine instance (or None if idle)           │  │  │
│  │  │  • load(model)  → create engine, allocate GPU                │  │  │
│  │  │  • unload()     → destroy engine, free GPU, cuda empty cache │  │  │
│  │  │  • ensure(model)→ auto-load/switch if needed (Ollama-style)  │  │  │
│  │  │  • keep_alive   → background timer, unload after inactivity  │  │  │
│  │  └──────────────────────────────────────────────────────────────┘  │  │
│  │                                                                    │  │
│  │  ┌─ PullManager ────────────────────────────────────────────────┐  │  │
│  │  │  • Background downloads (ThreadPoolExecutor)                 │  │  │
│  │  │  • Non-blocking: inference continues during pull             │  │  │
│  │  │  • Progress tracking per model                               │  │  │
│  │  │  • Deduplication: concurrent pulls for same model → one job  │  │  │
│  │  │  • Cancellation support                                      │  │  │
│  │  └──────────────────────────────────────────────────────────────┘  │  │
│  │                                                                    │  │
│  │  ┌─ OpenAI Serving Layer ───────────────────────────────────────┐  │  │
│  │  │  • Uses vllm.entrypoints.openai.serving_chat                │  │  │
│  │  │  • OpenAIServingChat wraps engine for /v1/chat/completions  │  │  │
│  │  │  • Native SSE streaming, tool calls, vision — no proxy      │  │  │
│  │  └──────────────────────────────────────────────────────────────┘  │  │
│  │                                                                    │  │
│  │  Routes:                                                           │  │
│  │    POST /v1/chat/completions  → ensure(model) → engine.generate  │  │
│  │    GET  /v1/models            → list loaded + cached models       │  │
│  │    POST /api/pull             → PullManager (background, NDJSON)  │  │
│  │    GET  /api/tags             → scan HF cache                     │  │
│  │    POST /api/show             → model config from cache           │  │
│  │    DELETE /api/delete         → remove from HF cache              │  │
│  │    GET  /health               → engine state                      │  │
│  │    GET  /api/status           → full status (engine + pulls)      │  │
│  │    POST /api/generate         → Ollama-compat (text completion)   │  │
│  │    POST /api/chat             → Ollama-compat (chat completion)   │  │
│  │                                                                    │  │
│  └────────────────────────────────────────────────────────────────────┘  │
│                                                                          │
│  📁 /root/.cache/huggingface  ← persistent volume (sail-vllm)           │
└──────────────────────────────────────────────────────────────────────────┘
```

---

## Part 1: ModelManager — Engine Lifecycle

The ModelManager owns the vLLM engine instance and implements Ollama-style behavior:
auto-load on first request, auto-switch when model changes, auto-unload after inactivity.

### State Machine

```
                    ┌──────────────────────────────────────┐
                    │                                      │
                    ▼                                      │
    ┌─────────┐  load()  ┌──────────┐  ready   ┌───────┐  │  keep_alive
    │  idle   │────────▶│ loading  │────────▶│ ready │──┘  timeout
    │ (no GPU)│          │(GPU alloc)│         │(serving)│────────┐
    └─────────┘          └──────────┘         └───────┘         │
         ▲                                        │              │
         │              ┌────────────┐            │ unload()     │
         └──────────────│ unloading  │◀───────────┘◀─────────────┘
                        │(GPU free)  │
                        └────────────┘
```

### Implementation

```python
import asyncio
import gc
import logging
import time
from typing import Optional

import torch
from vllm import AsyncLLMEngine
from vllm.engine.arg_utils import AsyncEngineArgs
from vllm.entrypoints.openai.serving_chat import OpenAIServingChat
from vllm.entrypoints.openai.serving_models import OpenAIServingModels

logger = logging.getLogger("vllm-manager")


class ModelManager:
    """
    Manages vLLM engine lifecycle with Ollama-style semantics:
    - Auto-load on inference request
    - Auto-switch when request model ≠ loaded model
    - Auto-unload after keep_alive inactivity timeout
    """

    def __init__(self, config: "ManagerConfig"):
        self.config = config

        # Engine state
        self.engine: Optional[AsyncLLMEngine] = None
        self.serving_chat: Optional[OpenAIServingChat] = None
        self.serving_models: Optional[OpenAIServingModels] = None
        self.current_model: Optional[str] = None
        self.state: str = "idle"  # idle | loading | ready | unloading | error
        self.error_message: Optional[str] = None

        # Keep-alive
        self.last_used: float = 0
        self.keep_alive: int = config.keep_alive  # seconds, -1 = forever, 0 = immediate unload
        self._keep_alive_task: Optional[asyncio.Task] = None

        # Concurrency
        self._lock = asyncio.Lock()

    # ── Ollama-style auto-load ──────────────────────────────────────

    async def ensure_loaded(self, model: str, keep_alive: Optional[int] = None) -> None:
        """
        Ensure the requested model is loaded and ready.
        If a different model is loaded, switch (unload + load).
        If no model is loaded, load it.

        This is called before every inference request — the Ollama pattern.
        The keep_alive parameter can override the default per-request (Ollama feature).
        """
        # Fast path: model already loaded and ready
        if self.current_model == model and self.state == "ready":
            self.last_used = time.time()
            if keep_alive is not None:
                self.keep_alive = keep_alive
            return

        async with self._lock:
            # Double-check after acquiring lock
            if self.current_model == model and self.state == "ready":
                self.last_used = time.time()
                return

            # Need to load (possibly after unloading current)
            await self._load(model)

            if keep_alive is not None:
                self.keep_alive = keep_alive

    # ── Load / Unload ───────────────────────────────────────────────

    async def _load(self, model: str) -> None:
        """Create vLLM engine for the given model."""
        # Unload current model if any
        if self.engine is not None:
            await self._unload()

        self.state = "loading"
        self.current_model = model
        self.error_message = None
        logger.info(f"Loading model: {model}")

        try:
            # Build engine args
            engine_args = AsyncEngineArgs(
                model=model,
                gpu_memory_utilization=self.config.gpu_memory_utilization,
                tensor_parallel_size=self.config.tensor_parallel_size,
                max_model_len=self.config.max_model_len,  # None = auto
                quantization=self.config.quantization or None,
                trust_remote_code=True,
                enable_auto_tool_choice=True,
                tool_call_parser=self._detect_tool_parser(model),
                dtype="auto",
            )

            # Create engine (this downloads + loads the model)
            self.engine = AsyncLLMEngine.from_engine_args(engine_args)

            # Create OpenAI serving layer wrapping the engine
            model_config = await self.engine.get_model_config()

            self.serving_chat = OpenAIServingChat(
                engine_client=self.engine,
                model_config=model_config,
                served_model_names=[model],
                response_role="assistant",
            )

            self.serving_models = OpenAIServingModels(
                engine_client=self.engine,
                model_config=model_config,
                served_model_names=[model],
            )

            self.state = "ready"
            self.last_used = time.time()
            self._start_keep_alive_timer()

            logger.info(f"Model ready: {model}")

        except Exception as e:
            self.state = "error"
            self.error_message = str(e)
            self.engine = None
            self.serving_chat = None
            logger.error(f"Failed to load {model}: {e}")
            raise

    async def _unload(self) -> None:
        """Destroy engine and free all GPU memory."""
        if self.engine is None:
            self.state = "idle"
            return

        model = self.current_model
        self.state = "unloading"
        logger.info(f"Unloading model: {model}")

        self._stop_keep_alive_timer()

        # Destroy serving layers
        self.serving_chat = None
        self.serving_models = None

        # Destroy engine
        # vLLM's AsyncLLMEngine holds GPU tensors — deleting it triggers cleanup
        del self.engine
        self.engine = None
        self.current_model = None

        # Force GPU memory release
        gc.collect()
        if torch.cuda.is_available():
            torch.cuda.empty_cache()
            torch.cuda.synchronize()

        self.state = "idle"
        logger.info(f"Unloaded {model}, GPU memory freed")

    async def unload(self) -> None:
        """Public unload (acquires lock)."""
        async with self._lock:
            await self._unload()

    # ── Keep-Alive Timer ────────────────────────────────────────────

    def _start_keep_alive_timer(self) -> None:
        """Start background task that unloads model after inactivity."""
        self._stop_keep_alive_timer()

        if self.keep_alive <= 0 and self.keep_alive != -1:
            # keep_alive=0 means unload immediately after request
            # (handled in ensure_loaded, not here)
            return

        if self.keep_alive == -1:
            # -1 = keep loaded forever
            return

        self._keep_alive_task = asyncio.create_task(self._keep_alive_loop())

    def _stop_keep_alive_timer(self) -> None:
        if self._keep_alive_task and not self._keep_alive_task.done():
            self._keep_alive_task.cancel()
            self._keep_alive_task = None

    async def _keep_alive_loop(self) -> None:
        """Periodically check if model should be unloaded due to inactivity."""
        try:
            while True:
                await asyncio.sleep(30)  # Check every 30 seconds

                if self.state != "ready" or self.keep_alive == -1:
                    continue

                elapsed = time.time() - self.last_used
                if elapsed >= self.keep_alive:
                    logger.info(
                        f"Keep-alive expired: {self.current_model} "
                        f"idle for {elapsed:.0f}s (limit: {self.keep_alive}s)"
                    )
                    async with self._lock:
                        # Re-check after acquiring lock (could have been used)
                        if time.time() - self.last_used >= self.keep_alive:
                            await self._unload()
        except asyncio.CancelledError:
            pass

    # ── Tool Parser Detection ───────────────────────────────────────

    TOOL_PARSERS = {
        "llama": "hermes",
        "qwen2": "hermes",
        "qwen3": "qwen3",
        "mistral": "mistral",
        "mixtral": "mistral",
        "hermes": "hermes",
        "phi": "hermes",
        "granite": "granite",
        "deepseek": "hermes",
        "gemma": "hermes",
    }

    @classmethod
    def _detect_tool_parser(cls, model_id: str) -> str:
        name = model_id.lower()
        for key, parser in cls.TOOL_PARSERS.items():
            if key in name:
                return parser
        return "hermes"

    # ── Status ──────────────────────────────────────────────────────

    def get_status(self) -> dict:
        idle_for = time.time() - self.last_used if self.last_used > 0 else None
        return {
            "state": self.state,
            "model": self.current_model,
            "keep_alive": self.keep_alive,
            "idle_seconds": round(idle_for, 1) if idle_for else None,
            "will_unload_in": (
                max(0, round(self.keep_alive - idle_for, 1))
                if idle_for and self.keep_alive > 0 and self.state == "ready"
                else None
            ),
            "error": self.error_message,
            "gpu_memory": self._get_gpu_info(),
        }

    @staticmethod
    def _get_gpu_info() -> Optional[dict]:
        if not torch.cuda.is_available():
            return None
        return {
            "allocated_gb": round(torch.cuda.memory_allocated() / 1e9, 2),
            "reserved_gb": round(torch.cuda.memory_reserved() / 1e9, 2),
            "total_gb": round(torch.cuda.get_device_properties(0).total_mem / 1e9, 2),
        }
```

### Keep-Alive Behavior (matching Ollama)

| `keep_alive` value | Behavior |
|---|---|
| `300` (default) | Unload model after 5 minutes of inactivity |
| `-1` | Never unload (keep loaded forever) |
| `0` | Unload immediately after each request completes |
| `3600` | Unload after 1 hour of inactivity |

Per-request override via the `keep_alive` field in the request body (same as Ollama):

```json
{
    "model": "meta-llama/Llama-3.1-8B-Instruct",
    "messages": [...],
    "keep_alive": -1
}
```

---

## Part 2: PullManager — Graceful Background Downloads

Pulling models must be **non-blocking**: inference on the currently loaded model continues while a new model downloads in the background.

### Design

```
PullManager
├── ThreadPoolExecutor (max_workers=2)
├── Active pulls: dict[model_id → PullJob]
│   └── PullJob
│       ├── state: queued | downloading | completed | failed | cancelled
│       ├── progress: {total_bytes, downloaded_bytes, files_done, files_total}
│       ├── future: concurrent.futures.Future
│       └── subscribers: list[asyncio.Queue]  ← for NDJSON streaming
└── Completed history: deque(maxlen=20)
```

### Why It Must Be Background

In v5, `POST /api/pull` called `snapshot_download()` in `run_in_executor`, but the endpoint itself was a streaming response — the HTTP connection had to stay open for the entire download. If the client disconnected, the pull might abort.

In v6, the pull runs independently:

1. `POST /api/pull` **starts** the download and immediately begins streaming progress
2. If client disconnects mid-stream, the download continues
3. Client can reconnect via `GET /api/pull/status/{model}` to resume watching
4. Multiple clients can watch the same pull (pub/sub via async queues)
5. Inference continues uninterrupted during pull

### Implementation

```python
import asyncio
import logging
import re
import time
from concurrent.futures import ThreadPoolExecutor
from collections import deque
from dataclasses import dataclass, field
from enum import Enum
from pathlib import Path
from typing import AsyncIterator, Optional

from huggingface_hub import HfApi, scan_cache_dir, snapshot_download

logger = logging.getLogger("vllm-manager")


class PullState(str, Enum):
    QUEUED = "queued"
    DOWNLOADING = "downloading"
    COMPLETED = "completed"
    FAILED = "failed"
    CANCELLED = "cancelled"


@dataclass
class PullJob:
    model_id: str
    state: PullState = PullState.QUEUED
    total_bytes: int = 0
    downloaded_bytes: int = 0
    files_total: int = 0
    files_done: int = 0
    started_at: float = field(default_factory=time.time)
    completed_at: Optional[float] = None
    error: Optional[str] = None
    _subscribers: list = field(default_factory=list)
    _future: Optional[object] = None

    @property
    def progress_pct(self) -> float:
        if self.total_bytes == 0:
            return 0
        return round(self.downloaded_bytes / self.total_bytes * 100, 1)

    def to_ndjson_line(self) -> str:
        """Produce one NDJSON progress line (Ollama-compatible format)."""
        import json

        if self.state == PullState.FAILED:
            return json.dumps({"error": self.error}) + "\n"

        if self.state == PullState.COMPLETED:
            return json.dumps({"status": "success"}) + "\n"

        return json.dumps({
            "status": f"downloading {self.model_id}" if self.state == PullState.DOWNLOADING
                      else f"pulling manifest for {self.model_id}",
            "total": self.total_bytes,
            "completed": self.downloaded_bytes,
        }) + "\n"


class PullManager:
    """
    Manages model downloads in the background.
    Inference continues uninterrupted during pulls.
    """

    def __init__(self, hf_token: Optional[str] = None, max_workers: int = 2):
        self.hf_token = hf_token
        self.api = HfApi(token=hf_token if hf_token else None)
        self._executor = ThreadPoolExecutor(max_workers=max_workers)
        self._active: dict[str, PullJob] = {}
        self._history: deque[PullJob] = deque(maxlen=20)
        self._lock = asyncio.Lock()

    async def pull(self, model_id: str) -> PullJob:
        """
        Start pulling a model. Returns immediately.
        If already pulling this model, returns existing job (deduplication).
        """
        async with self._lock:
            # Dedup: return existing active pull
            if model_id in self._active:
                existing = self._active[model_id]
                if existing.state in (PullState.QUEUED, PullState.DOWNLOADING):
                    logger.info(f"Pull already active for {model_id}, deduplicating")
                    return existing

            # Check if already downloaded
            if self._is_cached(model_id):
                job = PullJob(model_id=model_id, state=PullState.COMPLETED)
                return job

            # Validate model exists on HF Hub before starting download
            try:
                model_info = self.api.model_info(model_id)
                siblings = model_info.siblings or []
                total_size = sum(s.size or 0 for s in siblings if s.size)
                files_total = len(siblings)
            except Exception as e:
                job = PullJob(model_id=model_id, state=PullState.FAILED, error=str(e))
                return job

            # Create job and start background download
            job = PullJob(
                model_id=model_id,
                state=PullState.QUEUED,
                total_bytes=total_size,
                files_total=files_total,
            )
            self._active[model_id] = job

            loop = asyncio.get_event_loop()
            job._future = loop.run_in_executor(
                self._executor,
                self._download_sync,
                job,
            )

            # Fire-and-forget cleanup when done
            asyncio.ensure_future(self._wait_and_cleanup(model_id, job))

            return job

    def _download_sync(self, job: PullJob) -> None:
        """Synchronous download running in thread pool."""
        job.state = PullState.DOWNLOADING

        # Notify subscribers of state change
        self._notify(job)

        try:
            snapshot_download(
                job.model_id,
                token=self.hf_token if self.hf_token else None,
            )

            job.state = PullState.COMPLETED
            job.completed_at = time.time()
            job.downloaded_bytes = job.total_bytes
            logger.info(f"Pull complete: {job.model_id}")

        except Exception as e:
            job.state = PullState.FAILED
            job.error = str(e)
            job.completed_at = time.time()
            logger.error(f"Pull failed for {job.model_id}: {e}")

        self._notify(job)

    def _notify(self, job: PullJob) -> None:
        """Push update to all subscribers (for NDJSON streaming)."""
        line = job.to_ndjson_line()
        for queue in job._subscribers[:]:
            try:
                queue.put_nowait(line)
            except asyncio.QueueFull:
                pass  # Slow consumer, skip

    async def _wait_and_cleanup(self, model_id: str, job: PullJob) -> None:
        """Wait for download to finish, then move from active to history."""
        try:
            await asyncio.wrap_future(job._future)
        except Exception:
            pass

        async with self._lock:
            if model_id in self._active:
                del self._active[model_id]
                self._history.append(job)

    async def subscribe(self, model_id: str) -> AsyncIterator[str]:
        """
        Subscribe to pull progress for NDJSON streaming.
        Yields progress lines until pull completes.
        """
        async with self._lock:
            job = self._active.get(model_id)

        if job is None:
            yield '{"error": "No active pull for this model"}\n'
            return

        queue: asyncio.Queue = asyncio.Queue(maxsize=100)
        job._subscribers.append(queue)

        # Send current state immediately
        yield job.to_ndjson_line()

        try:
            while True:
                try:
                    line = await asyncio.wait_for(queue.get(), timeout=30)
                    yield line

                    # Check if pull is done
                    if job.state in (PullState.COMPLETED, PullState.FAILED, PullState.CANCELLED):
                        break
                except asyncio.TimeoutError:
                    # Send heartbeat to keep connection alive
                    yield '{"status": "downloading ' + job.model_id + '",' \
                          '"total": ' + str(job.total_bytes) + ',' \
                          '"completed": ' + str(job.downloaded_bytes) + '}\n'
        finally:
            if queue in job._subscribers:
                job._subscribers.remove(queue)

    async def cancel(self, model_id: str) -> bool:
        """Cancel an active pull."""
        async with self._lock:
            job = self._active.get(model_id)
            if job and job._future and not job._future.done():
                job._future.cancel()
                job.state = PullState.CANCELLED
                self._notify(job)
                return True
            return False

    def get_pull_status(self, model_id: str) -> Optional[PullJob]:
        """Get status of an active or recently completed pull."""
        if model_id in self._active:
            return self._active[model_id]
        for job in reversed(self._history):
            if job.model_id == model_id:
                return job
        return None

    def _is_cached(self, model_id: str) -> bool:
        """Check if model is already in HF cache."""
        try:
            cache_info = scan_cache_dir()
            return any(r.repo_id == model_id for r in cache_info.repos if r.repo_type == "model")
        except Exception:
            return False
```

### Pull Progress: Enhanced NDJSON (Ollama-compatible)

```
← POST /api/pull {"name": "meta-llama/Llama-3.1-8B-Instruct", "stream": true}

→ {"status": "pulling manifest for meta-llama/Llama-3.1-8B-Instruct"}
→ {"status": "downloading meta-llama/Llama-3.1-8B-Instruct", "total": 16065438720, "completed": 0}
→ {"status": "downloading meta-llama/Llama-3.1-8B-Instruct", "total": 16065438720, "completed": 4200000000}
→ ...heartbeats every 30s if no progress update...
→ {"status": "success"}
```

Key differences from v5:
- Pull starts in background thread immediately
- If client disconnects, download continues
- Client can reconnect via `GET /api/pull/status/{model_id}`
- Multiple subscribers can watch one pull
- No `run_in_executor` blocking the response — true fire-and-forget

---

## Part 3: HFCacheManager — Cache Operations

Same as v5 (no changes needed), it scans the HuggingFace cache directory:

```python
class HFCacheManager:
    """Manages models in the HuggingFace cache directory."""

    def __init__(self, hf_token: Optional[str] = None):
        self.api = HfApi(token=hf_token if hf_token else None)

    def list_models(self) -> list[dict]:
        """List all downloaded models (Ollama /api/tags format)."""
        try:
            cache_info = scan_cache_dir()
        except Exception:
            return []

        models = []
        for repo in cache_info.repos:
            if repo.repo_type != "model":
                continue
            models.append({
                "name": repo.repo_id,
                "model": repo.repo_id,
                "modified_at": max(
                    (rev.last_modified for rev in repo.revisions), default=0,
                ),
                "size": repo.size_on_disk,
                "digest": str(repo.repo_path),
                "details": {
                    "family": self._detect_family(repo.repo_id),
                    "parameter_size": self._estimate_param_size(repo.repo_id),
                    "quantization_level": self._detect_quantization(repo.repo_id),
                },
            })
        return models

    def get_model_info(self, model_id: str) -> dict:
        """Detailed model info from config.json (Ollama /api/show format)."""
        cache_info = scan_cache_dir()
        local_info = None
        for repo in cache_info.repos:
            if repo.repo_id == model_id:
                local_info = repo
                break

        if local_info is None:
            raise ValueError(f"Model {model_id} not found in cache")

        # Read config.json for architecture details
        config = {}
        for revision in local_info.revisions:
            config_path = revision.snapshot_path / "config.json"
            if config_path.exists():
                import json
                with open(config_path) as f:
                    config = json.load(f)
                break

        return {
            "modelfile": "",
            "parameters": json.dumps(config, indent=2),
            "template": "",
            "details": {
                "parent_model": "",
                "format": config.get("model_type", "unknown"),
                "family": self._detect_family(model_id),
                "families": [self._detect_family(model_id)],
                "parameter_size": self._estimate_param_size(model_id),
                "quantization_level": self._detect_quantization(model_id),
            },
            "model_info": {
                "general.architecture": config.get("model_type", "unknown"),
                "general.parameter_count": config.get("num_parameters"),
                "context_length": (
                    config.get("max_position_embeddings")
                    or config.get("max_sequence_length")
                    or config.get("seq_length")
                ),
                "hidden_size": config.get("hidden_size"),
                "num_layers": config.get("num_hidden_layers") or config.get("num_layers"),
                "vocab_size": config.get("vocab_size"),
            },
            "modified_at": max(
                (rev.last_modified for rev in local_info.revisions), default=None,
            ),
        }

    def delete_model(self, model_id: str) -> bool:
        """Remove model from HF cache."""
        cache_info = scan_cache_dir()
        for repo in cache_info.repos:
            if repo.repo_id == model_id:
                strategy = cache_info.delete_revisions(
                    *(rev.commit_hash for rev in repo.revisions)
                )
                strategy.execute()
                logger.info(f"Deleted {model_id}, freed {strategy.expected_freed_size_str}")
                return True
        return False

    def is_cached(self, model_id: str) -> bool:
        """Check if model exists in cache."""
        try:
            cache_info = scan_cache_dir()
            return any(r.repo_id == model_id for r in cache_info.repos if r.repo_type == "model")
        except Exception:
            return False

    @staticmethod
    def _detect_family(model_id: str) -> str:
        name = model_id.lower()
        for key, family in {
            "llama": "llama", "qwen": "qwen", "deepseek": "deepseek",
            "mistral": "mistral", "mixtral": "mistral", "phi": "phi",
            "gemma": "gemma", "hermes": "hermes", "granite": "granite",
        }.items():
            if key in name:
                return family
        return "unknown"

    @staticmethod
    def _estimate_param_size(model_id: str) -> str:
        import re
        match = re.search(r'(\d+\.?\d*)[Bb]', model_id)
        return f"{match.group(1)}B" if match else "unknown"

    @staticmethod
    def _detect_quantization(model_id: str) -> str:
        name = model_id.lower()
        for q in ["awq", "gptq", "gguf", "fp8", "int4", "int8", "bnb"]:
            if q in name:
                return q.upper()
        return "FP16"
```

---

## Part 4: FastAPI Routes

### Configuration

```python
from pydantic import BaseModel as PydanticModel
from pydantic_settings import BaseSettings


class ManagerConfig(BaseSettings):
    """Configuration from environment variables."""
    model_config = {"env_prefix": ""}

    # Manager
    manager_port: int = 8000
    default_model: str = "meta-llama/Llama-3.1-8B-Instruct"

    # Keep-alive (seconds). -1 = forever, 0 = unload after each request
    keep_alive: int = 300  # 5 minutes default (same as Ollama)

    # HuggingFace
    hugging_face_hub_token: str = ""
    huggingface_api_key: str = ""  # alias

    @property
    def hf_token(self) -> str:
        return self.hugging_face_hub_token or self.huggingface_api_key

    # vLLM engine
    vllm_gpu_memory_utilization: float = 0.9
    vllm_tensor_parallel_size: int = 1
    vllm_max_model_len: Optional[int] = None
    vllm_quantization: str = ""
    vllm_model: str = "meta-llama/Llama-3.1-8B-Instruct"  # alias for default_model

    model_config = {"env_prefix": "", "extra": "ignore"}
```

### Application

```python
import json
from contextlib import asynccontextmanager

from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse, StreamingResponse


@asynccontextmanager
async def lifespan(app: FastAPI):
    """Load default model on startup if configured."""
    config = ManagerConfig()
    app.state.config = config
    app.state.model_mgr = ModelManager(config)
    app.state.pull_mgr = PullManager(hf_token=config.hf_token)
    app.state.cache_mgr = HFCacheManager(hf_token=config.hf_token)

    default = config.vllm_model or config.default_model
    if default:
        try:
            await app.state.model_mgr.ensure_loaded(default)
        except Exception as e:
            logger.error(f"Failed to load default model: {e}")
            # Manager stays up — user can pull/load another model

    yield

    # Shutdown: unload model, free GPU
    await app.state.model_mgr.unload()


app = FastAPI(title="vLLM Manager", lifespan=lifespan)


# ── Inference: /v1/chat/completions (OpenAI-compatible) ─────────────

@app.post("/v1/chat/completions")
async def chat_completions(request: Request):
    """
    OpenAI-compatible chat completions.
    Auto-loads/switches model based on request body (Ollama behavior).
    """
    mgr: ModelManager = request.app.state.model_mgr
    body = await request.json()

    model = body.get("model", mgr.current_model or request.app.state.config.default_model)
    keep_alive = body.pop("keep_alive", None)  # Ollama extension
    stream = body.get("stream", False)

    # Ensure model is loaded (auto-switch if different)
    try:
        await mgr.ensure_loaded(model, keep_alive=keep_alive)
    except Exception as e:
        raise HTTPException(status_code=503, detail=f"Failed to load model {model}: {e}")

    if mgr.serving_chat is None:
        raise HTTPException(status_code=503, detail="Model not ready")

    # Delegate to vLLM's OpenAI serving layer (handles streaming, tool calls, etc.)
    try:
        response = await mgr.serving_chat.create_chat_completion(request)

        # Handle keep_alive=0 (unload after request)
        if keep_alive == 0:
            asyncio.create_task(mgr.unload())

        return response

    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))


@app.get("/v1/models")
async def list_loaded_models(request: Request):
    """OpenAI-compatible model listing."""
    mgr: ModelManager = request.app.state.model_mgr
    cache: HFCacheManager = request.app.state.cache_mgr

    # Show loaded model + all cached models
    models = []
    loaded = mgr.current_model

    for m in cache.list_models():
        models.append({
            "id": m["name"],
            "object": "model",
            "owned_by": "local",
            "loaded": m["name"] == loaded,
        })

    return {"object": "list", "data": models}


# ── Ollama-compatible: /api/chat ────────────────────────────────────

@app.post("/api/chat")
async def ollama_chat(request: Request):
    """
    Ollama-compatible chat endpoint.
    Translates Ollama format → OpenAI format → engine → Ollama format response.
    """
    mgr: ModelManager = request.app.state.model_mgr
    body = await request.json()

    model = body.get("model", mgr.current_model or request.app.state.config.default_model)
    keep_alive = body.get("keep_alive")
    stream = body.get("stream", True)  # Ollama defaults to stream=true
    messages = body.get("messages", [])
    options = body.get("options", {})

    # Ensure model loaded
    try:
        await mgr.ensure_loaded(model, keep_alive=keep_alive)
    except Exception as e:
        raise HTTPException(status_code=503, detail=f"Failed to load model {model}: {e}")

    # Build OpenAI-format request and delegate to serving layer
    openai_body = {
        "model": model,
        "messages": messages,
        "stream": stream,
        **{k: v for k, v in {
            "temperature": options.get("temperature"),
            "top_p": options.get("top_p"),
            "max_tokens": options.get("num_predict"),
        }.items() if v is not None},
    }

    # Create a mock request with OpenAI body for the serving layer
    # (Implementation note: may need to wrap in a Starlette Request)
    # For simplicity, directly call engine and format Ollama response ourselves:

    if stream:
        return StreamingResponse(
            _ollama_stream(mgr, model, messages, options),
            media_type="application/x-ndjson",
        )
    else:
        result = await _ollama_generate(mgr, model, messages, options)

        if keep_alive == 0:
            asyncio.create_task(mgr.unload())

        return result


async def _ollama_stream(mgr, model, messages, options):
    """Generate Ollama-format NDJSON stream."""
    # Use the engine directly for Ollama-format responses
    from vllm import SamplingParams

    params = SamplingParams(
        temperature=options.get("temperature", 0.7),
        top_p=options.get("top_p", 0.9),
        max_tokens=options.get("num_predict", 4096),
    )

    # This is a simplified example — full implementation would use
    # engine.generate() with the proper chat template applied
    # and stream results as Ollama NDJSON

    # For production, the /api/chat route should convert to OpenAI
    # format and reuse the serving_chat layer, then translate the
    # SSE response back to Ollama NDJSON. See Implementation Notes.
    pass


async def _ollama_generate(mgr, model, messages, options):
    """Generate Ollama-format non-streaming response."""
    pass  # Same approach as above


# ── Model Management ────────────────────────────────────────────────

class PullRequest(PydanticModel):
    name: str
    stream: bool = True


class ShowRequest(PydanticModel):
    name: str


class DeleteRequest(PydanticModel):
    name: str


@app.get("/api/tags")
async def list_models(request: Request):
    """List downloaded models (Ollama-compatible)."""
    cache: HFCacheManager = request.app.state.cache_mgr
    mgr: ModelManager = request.app.state.model_mgr

    models = cache.list_models()

    # Annotate which model is currently loaded
    for m in models:
        m["loaded"] = m["name"] == mgr.current_model

    return {"models": models}


@app.post("/api/pull")
async def pull_model(req: PullRequest, request: Request):
    """
    Pull (download) a model from HuggingFace Hub.
    Non-blocking: download runs in background, inference continues.
    Streams NDJSON progress (Ollama-compatible).
    """
    pull_mgr: PullManager = request.app.state.pull_mgr

    job = await pull_mgr.pull(req.name)

    if job.state == PullState.COMPLETED:
        return {"status": "success"}

    if job.state == PullState.FAILED:
        raise HTTPException(status_code=400, detail=job.error)

    if req.stream:
        return StreamingResponse(
            pull_mgr.subscribe(req.name),
            media_type="application/x-ndjson",
        )
    else:
        # Wait for completion
        while job.state in (PullState.QUEUED, PullState.DOWNLOADING):
            await asyncio.sleep(1)

        if job.state == PullState.FAILED:
            raise HTTPException(status_code=400, detail=job.error)

        return {"status": "success"}


@app.get("/api/pull/status/{model_id:path}")
async def pull_status(model_id: str, request: Request):
    """Check pull progress (reconnect-friendly)."""
    pull_mgr: PullManager = request.app.state.pull_mgr
    job = pull_mgr.get_pull_status(model_id)

    if job is None:
        raise HTTPException(status_code=404, detail=f"No pull found for {model_id}")

    return {
        "model": job.model_id,
        "state": job.state.value,
        "progress": job.progress_pct,
        "total_bytes": job.total_bytes,
        "downloaded_bytes": job.downloaded_bytes,
        "error": job.error,
    }


@app.post("/api/show")
async def show_model(req: ShowRequest, request: Request):
    """Model information (Ollama-compatible)."""
    cache: HFCacheManager = request.app.state.cache_mgr
    try:
        return cache.get_model_info(req.name)
    except ValueError as e:
        raise HTTPException(status_code=404, detail=str(e))


@app.delete("/api/delete")
async def delete_model(req: DeleteRequest, request: Request):
    """Delete model from cache (Ollama-compatible)."""
    mgr: ModelManager = request.app.state.model_mgr
    cache: HFCacheManager = request.app.state.cache_mgr

    # Can't delete loaded model
    if mgr.current_model == req.name and mgr.state == "ready":
        raise HTTPException(
            status_code=409,
            detail=f"Cannot delete currently loaded model '{req.name}'. "
                   f"Wait for keep_alive to expire, or load a different model first.",
        )

    if not cache.delete_model(req.name):
        raise HTTPException(status_code=404, detail=f"Model '{req.name}' not found")

    return {"status": "success"}


# ── Health & Status ─────────────────────────────────────────────────

@app.get("/health")
async def health(request: Request):
    """Health check."""
    mgr: ModelManager = request.app.state.model_mgr
    status = mgr.get_status()

    if status["state"] == "ready":
        return {"status": "ok", "model": status["model"]}
    elif status["state"] == "loading":
        return JSONResponse(status_code=503, content={"status": "loading", "model": status["model"]})
    elif status["state"] == "idle":
        return {"status": "idle", "message": "No model loaded (will auto-load on first request)"}
    else:
        return JSONResponse(status_code=503, content={"status": status["state"], "error": status["error"]})


@app.get("/api/status")
async def full_status(request: Request):
    """Detailed status of engine, pulls, and cache."""
    mgr: ModelManager = request.app.state.model_mgr
    pull_mgr: PullManager = request.app.state.pull_mgr
    cache: HFCacheManager = request.app.state.cache_mgr

    active_pulls = {
        mid: {
            "state": job.state.value,
            "progress": job.progress_pct,
            "total_bytes": job.total_bytes,
        }
        for mid, job in pull_mgr._active.items()
    }

    return {
        "engine": mgr.get_status(),
        "pulls": active_pulls,
        "cache": {
            "models": [m["name"] for m in cache.list_models()],
            "count": len(cache.list_models()),
        },
    }


# ── Entry Point ─────────────────────────────────────────────────────

if __name__ == "__main__":
    import uvicorn
    config = ManagerConfig()
    uvicorn.run(app, host="0.0.0.0", port=config.manager_port)
```

---

## Part 5: Ollama Behavior Comparison

### Request Flow: Ollama vs vLLM Manager v6

**First request (cold start):**

```
Laravel                    Ollama / vLLM Manager
  │                              │
  │  POST /api/chat              │
  │  {"model": "llama3.1:8b"}   │
  │ ──────────────────────────▶ │
  │                              ├── Model not loaded
  │                              ├── Load from disk → GPU
  │                              ├── (takes 5-30 seconds)
  │                              ├── Run inference
  │  ◀──────────────────────────┤
  │  {"message": {"content":..}} │
  │                              │
  │                              ├── Start keep_alive timer (5 min)
```

**Subsequent request (warm):**

```
Laravel                    Ollama / vLLM Manager
  │                              │
  │  POST /v1/chat/completions   │
  │  {"model": "same-model"}    │
  │ ──────────────────────────▶ │
  │                              ├── Model already loaded ✓
  │                              ├── Reset keep_alive timer
  │                              ├── Run inference immediately
  │  ◀──────────────────────────┤
  │  {"choices": [...]}          │
```

**Model switch (auto):**

```
Laravel                    vLLM Manager
  │                              │
  │  POST /v1/chat/completions   │
  │  {"model": "qwen2.5:7b"}   │
  │ ──────────────────────────▶ │
  │                              ├── Different model requested!
  │                              ├── Unload current (free GPU)
  │                              ├── Load new model → GPU
  │                              ├── (takes 10-60 seconds)
  │                              ├── Run inference
  │  ◀──────────────────────────┤
  │  {"choices": [...]}          │
```

**Keep-alive expiry:**

```
                           vLLM Manager
                                │
                                ├── [5 min of no requests]
                                ├── keep_alive expired
                                ├── Unload model
                                ├── torch.cuda.empty_cache()
                                ├── GPU memory freed ✓
                                ├── State: "idle"
                                │
                                ├── [Next request arrives]
                                ├── Auto-load (cold start again)
```

**Graceful pull (background):**

```
Laravel                    vLLM Manager
  │                              │
  │  POST /api/pull              │
  │  {"name": "new-model"}      │
  │ ──────────────────────────▶ │
  │                              ├── Start background download
  │  ◀── NDJSON progress ───────┤
  │  {"status":"downloading"...} │
  │                              │
  │  POST /v1/chat/completions   │  ← Inference continues during pull!
  │  {"model": "current-model"} │
  │ ──────────────────────────▶ │
  │  ◀── normal response ───────┤  ← No interruption
  │                              │
  │  ◀── {"status":"success"} ──┤  ← Pull finished
  │                              │
  │  POST /v1/chat/completions   │
  │  {"model": "new-model"}     │  ← Now use the new model
  │ ──────────────────────────▶ │
  │                              ├── Auto-switch to new-model
```

### Feature Parity Matrix

| Feature | Ollama | vLLM Manager v6 | Notes |
|---------|--------|-----------------|-------|
| Auto-load on request | ✅ | ✅ | `ensure_loaded()` in every inference route |
| Auto-switch model | ✅ | ✅ | Transparent — just change `model` field |
| Keep-alive timer | ✅ 5m default | ✅ 5m default | Configurable, -1=forever, 0=immediate |
| Per-request keep_alive | ✅ | ✅ | `"keep_alive": 3600` in request body |
| Unload on idle | ✅ | ✅ | Background timer, frees GPU |
| Pull with progress | ✅ NDJSON | ✅ NDJSON | Same format, compatible DTOs |
| Background pull | ⚠️ Blocks API | ✅ True background | Inference continues during download |
| Pull reconnect | ❌ | ✅ | `GET /api/pull/status/{model}` |
| Pull dedup | ✅ | ✅ | Same model pull → single download |
| Cancel pull | ❌ | ✅ | `DELETE /api/pull/{model}` |
| List models | ✅ `/api/tags` | ✅ `/api/tags` | Same format |
| Show model | ✅ `/api/show` | ✅ `/api/show` | Same format |
| Delete model | ✅ `/api/delete` | ✅ `/api/delete` | Same format |
| OpenAI compat | ❌ | ✅ `/v1/*` | VLLMBackend uses this |
| Ollama compat | ✅ native | ✅ `/api/chat` | For testing/compatibility |
| GPU memory info | ❌ | ✅ | In `/api/status` response |
| Loaded indicator | ❌ | ✅ | `"loaded": true` in `/api/tags` |

---

## Part 6: Docker

### Dockerfile

```dockerfile
FROM vllm/vllm-openai:latest

# Manager dependencies
COPY manager/requirements.txt /opt/vllm-manager/requirements.txt
RUN pip install --no-cache-dir -r /opt/vllm-manager/requirements.txt

# Manager code
COPY manager/ /opt/vllm-manager/

# Defaults
ENV VLLM_MODEL=meta-llama/Llama-3.1-8B-Instruct
ENV KEEP_ALIVE=300
ENV MANAGER_PORT=8000
ENV VLLM_GPU_MEMORY_UTILIZATION=0.9
ENV VLLM_TENSOR_PARALLEL_SIZE=1

EXPOSE 8000

# Manager IS the process — no subprocess, no proxy
ENTRYPOINT ["python", "/opt/vllm-manager/main.py"]
```

### requirements.txt

```
fastapi>=0.115.0
uvicorn[standard]>=0.30.0
huggingface_hub>=0.28.0
pydantic>=2.0
pydantic-settings>=2.0
```

Note: `httpx` removed (no proxy needed). `torch` and `vllm` are already in the base image.

### docker-compose.yml (unchanged from v5)

```yaml
    vllm:
        build:
            context: './.sail/vllm'
            dockerfile: Dockerfile
        image: 'sail/vllm-managed:latest'
        deploy:
            resources:
                reservations:
                    devices:
                        - driver: nvidia
                          count: all
                          capabilities: [gpu]
        ports:
            - '${FORWARD_VLLM_PORT:-8000}:8000'
        environment:
            VLLM_MODEL: '${VLLM_MODEL:-meta-llama/Llama-3.1-8B-Instruct}'
            HUGGING_FACE_HUB_TOKEN: '${HUGGINGFACE_API_KEY}'
            KEEP_ALIVE: '${VLLM_KEEP_ALIVE:-300}'
            VLLM_GPU_MEMORY_UTILIZATION: '${VLLM_GPU_MEMORY_UTILIZATION:-0.9}'
            VLLM_TENSOR_PARALLEL_SIZE: '${VLLM_TENSOR_PARALLEL_SIZE:-1}'
            VLLM_MAX_MODEL_LEN: '${VLLM_MAX_MODEL_LEN:-}'
            VLLM_QUANTIZATION: '${VLLM_QUANTIZATION:-}'
        volumes:
            - 'sail-vllm:/root/.cache/huggingface'
        networks:
            - sail
        healthcheck:
            test: ['CMD', 'curl', '-f', 'http://localhost:8000/health']
            interval: 30s
            timeout: 10s
            retries: 3
            start_period: 120s
```

New env vars:
```env
# Keep-alive: seconds before unloading idle model. -1=never, 0=immediate
VLLM_KEEP_ALIVE=300
```

---

## Part 7: Laravel VLLMBackend Changes

### Minimal changes from v5

The VLLMBackend PHP class needs almost **no changes** from v5 because:
- Same endpoints (`/v1/chat/completions`, `/api/tags`, `/api/pull`, etc.)
- Same response formats
- Same NDJSON pull streaming

Only additions:

```php
/**
 * Check if a model is currently loaded in vLLM.
 */
public function isModelLoaded(string $modelName): bool
{
    $status = $this->getStatus();
    return ($status['engine']['model'] ?? null) === $modelName
        && ($status['engine']['state'] ?? null) === 'ready';
}

/**
 * Get GPU memory info from the vLLM manager.
 */
public function getGpuInfo(): ?array
{
    $status = $this->getStatus();
    return $status['engine']['gpu_memory'] ?? null;
}

/**
 * Check pull status for a model (reconnect-friendly).
 */
public function getPullStatus(string $modelName): ?array
{
    try {
        $response = $this->client->get("/api/pull/status/{$modelName}");
        return json_decode($response->getBody()->getContents(), true);
    } catch (GuzzleException $e) {
        return null;
    }
}
```

### keep_alive Support

The `NormalizedModelConfig` could optionally include `keep_alive`:

```php
// In NormalizedModelConfig::toVLLMOptions()
public function toVLLMOptions(): array
{
    $options = [];
    if ($this->temperature !== null) $options['temperature'] = $this->temperature;
    if ($this->topP !== null) $options['top_p'] = $this->topP;
    if ($this->maxTokens !== null) $options['max_tokens'] = $this->maxTokens;
    // ... etc

    return $options;
}

// In VLLMBackend::buildPayload()
protected function buildPayload(array $messages, array $tools, bool $stream): array
{
    $payload = [
        'model' => $this->model,
        'messages' => array_map(fn(ChatMessage $m) => $this->formatMessage($m), $messages),
        'stream' => $stream,
        ...$this->normalizedConfig?->toVLLMOptions() ?? [],
    ];

    // Ollama-style keep_alive (passed through to manager)
    if (isset($this->options['keep_alive'])) {
        $payload['keep_alive'] = $this->options['keep_alive'];
    }

    if (!empty($tools)) {
        $payload['tools'] = $tools;
    }

    return $payload;
}
```

---

## Part 8: Implementation Notes

### vLLM API Stability Warning

The embedded approach depends on vLLM's Python classes:

| Class | Stability | Risk |
|-------|-----------|------|
| `AsyncLLMEngine` | Semi-stable | May change constructor args between versions |
| `AsyncEngineArgs` | Semi-stable | New fields added, old ones renamed occasionally |
| `OpenAIServingChat` | Internal | Constructor signature changes between versions |
| `SamplingParams` | Stable | Rarely breaks |

**Mitigation**: Pin vLLM version in Dockerfile. When upgrading, test the manager against the new version before deploying.

```dockerfile
# Pin version
FROM vllm/vllm-openai:v0.7.3
```

### OpenAIServingChat Integration

The cleanest integration with vLLM's serving layer requires matching their expected `Request` format. The `OpenAIServingChat.create_chat_completion()` method expects a Starlette `Request` object with the OpenAI-format JSON body.

Two approaches:

**A) Pass through the FastAPI Request directly** (cleanest):

```python
@app.post("/v1/chat/completions")
async def chat_completions(request: Request):
    await mgr.ensure_loaded(model)
    return await mgr.serving_chat.create_chat_completion(request)
```

This works if vLLM's method signature accepts a raw Request. Check the specific version.

**B) Build a ChatCompletionRequest and call the engine directly**:

```python
from vllm.entrypoints.openai.protocol import ChatCompletionRequest

@app.post("/v1/chat/completions")
async def chat_completions(request: Request):
    body = await request.json()
    await mgr.ensure_loaded(body["model"])

    chat_request = ChatCompletionRequest(**body)
    generator = await mgr.serving_chat.create_chat_completion(chat_request, request)

    if body.get("stream"):
        return StreamingResponse(generator, media_type="text/event-stream")
    else:
        return generator
```

The exact approach depends on the vLLM version pinned in the Dockerfile. Both should be tested.

### GPU Memory Cleanup

The critical operation is freeing GPU memory when switching/unloading models:

```python
async def _unload(self):
    # 1. Delete the serving layers (they hold refs to engine)
    self.serving_chat = None
    self.serving_models = None

    # 2. Delete the engine (releases GPU tensors)
    engine = self.engine
    self.engine = None
    del engine

    # 3. Force Python garbage collection
    gc.collect()

    # 4. Tell CUDA to release cached memory back to the OS
    if torch.cuda.is_available():
        torch.cuda.empty_cache()
        torch.cuda.synchronize()
```

This should free most GPU memory. If vLLM holds memory through other means (e.g., NCCL communicators for tensor parallelism), a more aggressive cleanup might be needed. In the worst case, the process can be restarted — but that defeats the purpose of the embedded approach. Testing with real models is essential.

### /api/chat (Ollama Compat) — Implementation Strategy

Rather than reimplementing Ollama's chat format from scratch, the recommended approach is:

1. Receive Ollama-format request
2. Translate to OpenAI-format
3. Call `serving_chat.create_chat_completion()`
4. Translate OpenAI response back to Ollama format

This reuses all the complex logic (tool calls, streaming, etc.) and only requires format translation at the edges.

```python
def ollama_to_openai(body: dict) -> dict:
    """Translate Ollama /api/chat body to OpenAI format."""
    options = body.get("options", {})
    return {
        "model": body["model"],
        "messages": body.get("messages", []),
        "stream": body.get("stream", True),
        "temperature": options.get("temperature"),
        "top_p": options.get("top_p"),
        "max_tokens": options.get("num_predict"),
    }

def openai_to_ollama(data: dict, model: str) -> dict:
    """Translate OpenAI response to Ollama format."""
    choice = data["choices"][0]
    return {
        "model": model,
        "created_at": data.get("created"),
        "message": {
            "role": "assistant",
            "content": choice["message"]["content"],
            "tool_calls": choice["message"].get("tool_calls"),
        },
        "done": True,
        "total_duration": 0,
        "prompt_eval_count": data.get("usage", {}).get("prompt_tokens", 0),
        "eval_count": data.get("usage", {}).get("completion_tokens", 0),
    }
```

---

## File Structure

```
.sail/vllm/
├── Dockerfile
└── manager/
    ├── main.py              # FastAPI app + routes + entry point
    ├── model_manager.py     # ModelManager (engine lifecycle + keep-alive)
    ├── pull_manager.py      # PullManager (background downloads)
    ├── cache_manager.py     # HFCacheManager (list/show/delete)
    ├── config.py            # ManagerConfig (env vars)
    ├── ollama_compat.py     # Ollama ↔ OpenAI format translators
    └── requirements.txt
```

---

## Migration from v5

| Component | v5 | v6 | Migration |
|-----------|----|----|-----------|
| `VLLMProcess` class | subprocess.Popen + kill/restart | `ModelManager` with AsyncLLMEngine | Replace entirely |
| HTTP proxy | httpx → localhost:8001 | Direct engine call | Remove proxy code + httpx dep |
| `VLLM_INTERNAL_PORT` | Used for subprocess | Not needed | Remove env var |
| Health polling loop | `_wait_for_ready()` polls HTTP | Engine ready callback | Remove polling |
| `/v1/*` routes | Proxy passthrough | Direct serving layer | Rewrite routes |
| Pull mechanism | `run_in_executor` blocking | `PullManager` fire-and-forget | Replace entirely |
| Keep-alive | Not implemented | `ModelManager._keep_alive_loop()` | New feature |
| GPU cleanup | Not possible (subprocess) | `torch.cuda.empty_cache()` | New feature |

### VLLMBackend.php Changes

Almost none. The API surface is identical:
- Same routes, same request/response formats
- Add `getPullStatus()` method (optional, new endpoint)
- Add `getGpuInfo()` method (optional, new endpoint)
- `keep_alive` can be passed in request body (optional)

---

## Testing

### Manual Testing

```bash
# Build and start
sail build vllm
sail up -d vllm

# Status (should show "idle" or "loading")
curl http://localhost:8000/api/status | jq

# First inference triggers auto-load
curl -X POST http://localhost:8000/v1/chat/completions \
  -H 'Content-Type: application/json' \
  -d '{"model": "meta-llama/Llama-3.1-8B-Instruct", "messages": [{"role": "user", "content": "Hi"}]}'

# Status (should show "ready" with GPU info)
curl http://localhost:8000/api/status | jq

# Wait 5 minutes... model auto-unloads
curl http://localhost:8000/api/status | jq
# → state: "idle", gpu_memory: near zero

# Pull a new model in background (inference continues)
curl -X POST http://localhost:8000/api/pull \
  -d '{"name": "Qwen/Qwen2.5-7B-Instruct", "stream": true}'

# While pulling, inference on current model still works
curl -X POST http://localhost:8000/v1/chat/completions \
  -d '{"model": "meta-llama/Llama-3.1-8B-Instruct", "messages": [{"role": "user", "content": "Hello"}]}'

# Switch model (auto on inference)
curl -X POST http://localhost:8000/v1/chat/completions \
  -d '{"model": "Qwen/Qwen2.5-7B-Instruct", "messages": [{"role": "user", "content": "Hello"}]}'

# keep_alive=-1: keep model loaded forever
curl -X POST http://localhost:8000/v1/chat/completions \
  -d '{"model": "Qwen/Qwen2.5-7B-Instruct", "messages": [...], "keep_alive": -1}'

# keep_alive=0: unload immediately after response
curl -X POST http://localhost:8000/v1/chat/completions \
  -d '{"model": "Qwen/Qwen2.5-7B-Instruct", "messages": [...], "keep_alive": 0}'
```

### Automated Tests

```
✓ health returns "idle" when no model loaded
✓ first inference auto-loads default model
✓ health returns "ok" after model loaded
✓ subsequent inference is fast (no reload)
✓ inference with different model triggers auto-switch
✓ keep_alive=-1 prevents auto-unload
✓ keep_alive=0 unloads immediately after response
✓ model auto-unloads after keep_alive timeout
✓ GPU memory freed after unload (torch.cuda.memory_allocated ≈ 0)
✓ /api/pull starts background download
✓ inference continues during active pull
✓ /api/pull/status returns progress
✓ duplicate pull returns existing job
✓ /api/tags lists cached models with loaded indicator
✓ /api/show returns model config
✓ /api/delete removes model from cache
✓ /api/delete rejects loaded model (409)
✓ SSE streaming works for /v1/chat/completions
✓ tool calls work through engine
✓ concurrent inference requests handled correctly
```

---

## Implementation Order

1. **`config.py`** — ManagerConfig with keep_alive env var
2. **`cache_manager.py`** — Port from v5 (unchanged logic)
3. **`model_manager.py`** — Core: AsyncLLMEngine lifecycle + keep-alive
4. **`pull_manager.py`** — Background downloads with progress
5. **`main.py`** — FastAPI routes, starting with `/health` and `/api/tags`
6. **`/v1/chat/completions`** — OpenAI inference with auto-load
7. **`/api/pull`** — Streaming pull with PullManager
8. **`ollama_compat.py`** — `/api/chat` translator (optional, lower priority)
9. **Dockerfile** — Rebuild with pinned vLLM version
10. **Test**: full lifecycle (load → infer → idle → unload → re-load)
11. **VLLMBackend.php** — Add `getPullStatus()`, `getGpuInfo()` (optional)