"""Async SSE handler for TUI."""

import asyncio
import threading
from concurrent.futures import ThreadPoolExecutor
from typing import AsyncIterator, Tuple, Dict, Any, Optional
from queue import Queue, Empty

from ...api import APIClient, SSEClient


class SSEHandler:
    """Async wrapper for SSE client to work with Textual's async model."""

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

                # Check for terminal events
                if event_type in ("completed", "failed", "cancelled"):
                    break

        except Exception as e:
            self._queue.put(("error", {"error": str(e)}))
        finally:
            # Signal end of stream
            self._queue.put(None)

    async def stream(self) -> AsyncIterator[Tuple[str, Dict[str, Any]]]:
        """
        Stream SSE events asynchronously.

        Yields:
            Tuple of (event_type, data)
        """
        # Start SSE in background thread
        self._thread = threading.Thread(target=self._run_sse_sync, daemon=True)
        self._thread.start()

        try:
            while not self._closed:
                # Check queue with timeout to allow event loop to process
                try:
                    # Use asyncio to check the queue without blocking
                    loop = asyncio.get_event_loop()
                    item = await loop.run_in_executor(
                        None,
                        lambda: self._queue.get(timeout=0.1)
                    )

                    if item is None:
                        # End of stream
                        break

                    event_type, data = item
                    yield event_type, data

                    # Check for terminal events
                    if event_type in ("completed", "failed", "cancelled", "error"):
                        break

                except Empty:
                    # Queue empty, continue waiting
                    await asyncio.sleep(0.05)
                    continue

        finally:
            self.close()

    def close(self) -> None:
        """Close the SSE connection."""
        self._closed = True
        if self._sse_client:
            self._sse_client.close()
            self._sse_client = None
