"""Async SSE handler bridging sync SSE to Textual's async loop."""

import asyncio
import threading
from typing import AsyncIterator, Tuple, Dict, Any, Optional
from queue import Queue, Empty

from ...api import APIClient, SSEClient


class StreamHandler:
    """Bridge between the sync SSE client and Textual's async event loop.

    Runs the SSE client in a background thread and exposes events
    via an async generator that is safe to iterate in Textual workers.
    """

    def __init__(self, client: APIClient, conversation_id: int) -> None:
        self.client = client
        self.conversation_id = conversation_id
        self._sse_client: Optional[SSEClient] = None
        self._closed = False
        self._queue: Queue = Queue()
        self._thread: Optional[threading.Thread] = None

    def _run_sse_sync(self) -> None:
        """Run SSE client in a separate thread."""
        try:
            self._sse_client = SSEClient(
                base_url=self.client.base_url,
                conversation_id=self.conversation_id,
                headers=self.client._get_headers(),
                timeout=120,
            )

            for event_type, data in self._sse_client.events():
                if self._closed:
                    break
                self._queue.put((event_type, data))
                # tool_request stops the stream because the server closes
                # the SSE connection after emitting it (client must handle
                # the tool, submit results, then reconnect).
                # tool_executing / tool_completed are server-side events
                # where the stream stays open — do NOT break on those.
                if event_type in ("completed", "failed", "cancelled", "tool_request"):
                    break

        except Exception as e:
            self._queue.put(("error", {"error": str(e)}))
        finally:
            self._queue.put(None)

    async def stream(self) -> AsyncIterator[Tuple[str, Dict[str, Any]]]:
        """Async generator yielding (event_type, data) tuples.

        Uses non-blocking queue polling so we never occupy a thread
        from the default executor — Textual needs those threads for
        its own work (rendering, CSS, etc.).
        """
        self._thread = threading.Thread(target=self._run_sse_sync, daemon=True)
        self._thread.start()

        try:
            while not self._closed:
                # Drain all available events before yielding back
                try:
                    item = self._queue.get_nowait()
                except Empty:
                    # Nothing ready — yield to event loop so Textual
                    # can process repaints and input events.
                    await asyncio.sleep(0.02)
                    continue

                if item is None:
                    break

                event_type, data = item
                yield event_type, data

                if event_type in ("completed", "failed", "cancelled", "error", "tool_request"):
                    break
        finally:
            self.close()

    def close(self) -> None:
        """Close the SSE connection."""
        self._closed = True
        if self._sse_client:
            self._sse_client.close()
            self._sse_client = None
