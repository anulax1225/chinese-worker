"""Server-Sent Events (SSE) client for real-time conversation updates."""

import json
import httpx
from typing import Callable, Dict, Any, Optional, Iterator, Tuple


class SSEClient:
    """Server-Sent Events client for conversation updates."""

    def __init__(
        self,
        base_url: str,
        conversation_id: int,
        headers: Dict[str, str],
        timeout: int = 60,
    ):
        """
        Initialize SSE client.

        Args:
            base_url: Base URL of the API
            conversation_id: Conversation ID to stream
            headers: Authentication headers
            timeout: Read timeout in seconds
        """
        self.url = f"{base_url}/api/v1/conversations/{conversation_id}/stream"
        self.headers = {
            **headers,
            "Accept": "text/event-stream",
            "Cache-Control": "no-cache",
        }
        self.timeout = timeout
        self._response: Optional[httpx.Response] = None

    def events(self) -> Iterator[Tuple[str, Dict[str, Any]]]:
        """
        Connect to SSE stream and yield events.

        Yields:
            Tuple of (event_type, data_dict) for each event

        Raises:
            httpx.HTTPStatusError: If connection fails
            httpx.ReadTimeout: If connection times out
        """
        with httpx.stream(
            "GET",
            self.url,
            headers=self.headers,
            timeout=httpx.Timeout(connect=5.0, read=self.timeout, write=5.0, pool=5.0),
        ) as response:
            self._response = response
            response.raise_for_status()

            event_type: Optional[str] = None
            data_buffer: str = ""

            try:
                for line in response.iter_lines():
                    # Skip comments (padding)
                    if line.startswith(":"):
                        continue

                    # Empty line signals end of event
                    if line == "":
                        if event_type and data_buffer:
                            try:
                                data = json.loads(data_buffer)
                                yield (event_type, data)
                            except json.JSONDecodeError:
                                pass  # Skip malformed data

                            # Check for terminal events
                            if event_type in ("completed", "failed", "cancelled", "tool_request"):
                                return

                        event_type = None
                        data_buffer = ""
                        continue

                    if line.startswith("event:"):
                        event_type = line[6:].strip()
                    elif line.startswith("data:"):
                        data_buffer += line[5:].strip()
            finally:
                self._response = None

    def close(self) -> None:
        """Close the SSE connection explicitly."""
        if self._response:
            try:
                self._response.close()
            except Exception:
                pass  # Ignore close errors
            self._response = None

    def connect(
        self,
        on_event: Callable[[str, Dict[str, Any]], bool],
        timeout: int = 5,
    ) -> bool:
        """
        Connect to SSE stream and process events via callback.

        Args:
            on_event: Callback function (event_type, data) -> continue_listening
            timeout: Connection timeout in seconds

        Returns:
            True if connected and processed events, False if connection failed
        """
        try:
            for event_type, data in self.events():
                should_continue = on_event(event_type, data)
                if not should_continue:
                    return True
            return True
        except (httpx.ConnectError, httpx.ReadTimeout, httpx.HTTPStatusError):
            return False


class SSEEventHandler:
    """Helper class to accumulate SSE events for the CLI."""

    def __init__(self):
        """Initialize event handler."""
        self.content: str = ""
        self.thinking: str = ""
        self.tool_request: Optional[Dict[str, Any]] = None
        self.status: str = "processing"
        self.error: Optional[str] = None
        self.stats: Dict[str, int] = {"turns": 0, "tokens": 0}
        self.messages: list = []
        self.current_tool: Optional[str] = None

    def handle_event(self, event_type: str, data: Dict[str, Any]) -> bool:
        """
        Handle an SSE event.

        Args:
            event_type: Type of event
            data: Event data

        Returns:
            True to continue listening, False to stop
        """
        if event_type == "connected":
            return True

        elif event_type == "text_chunk":
            chunk = data.get("chunk", "")
            chunk_type = data.get("type", "content")
            if chunk_type == "thinking":
                self.thinking += chunk
            else:
                self.content += chunk
            return True

        elif event_type == "status_changed":
            self.status = data.get("status", "processing")
            if "stats" in data:
                self.stats = data["stats"]
            return True

        elif event_type == "tool_request":
            self.status = "waiting_for_tool"
            self.tool_request = data.get("tool_request")
            if "stats" in data:
                self.stats = data["stats"]
            return False  # Stop listening, CLI needs to handle tool

        elif event_type == "completed":
            self.status = "completed"
            if "stats" in data:
                self.stats = data["stats"]
            if "messages" in data:
                self.messages = data["messages"]
            return False  # Done

        elif event_type == "failed":
            self.status = "failed"
            self.error = data.get("error")
            if "stats" in data:
                self.stats = data["stats"]
            return False  # Done

        elif event_type == "cancelled":
            self.status = "cancelled"
            if "stats" in data:
                self.stats = data["stats"]
            return False  # Done

        elif event_type == "tool_executing":
            # Tool started executing (system tool on server)
            tool = data.get("tool", {})
            self.current_tool = tool.get("name", "unknown")
            return True

        elif event_type == "tool_completed":
            # Tool finished executing
            self.current_tool = None
            return True

        return True  # Unknown event, continue
