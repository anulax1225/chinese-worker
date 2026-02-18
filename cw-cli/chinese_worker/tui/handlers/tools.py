"""Tool execution handler for TUI."""

import asyncio
from typing import Dict, Any, List, Optional, Tuple, Callable, TYPE_CHECKING

from ...tools.base import BaseTool

if TYPE_CHECKING:
    from ...api import APIClient


class ToolExecutor:
    """Executes client-side tools and submits results to the server.

    Decoupled from ChatScreen — communicates status through an optional
    message callback so it can be unit-tested independently.
    """

    def __init__(
        self,
        tools: Dict[str, BaseTool],
        client: "APIClient",
        on_message: Optional[Callable[[str], None]] = None,
    ) -> None:
        self.tools = tools
        self.client = client
        self._on_message = on_message

    def get_tool_names(self) -> List[str]:
        return list(self.tools.keys())

    def _msg(self, text: str) -> None:
        if self._on_message:
            self._on_message(text)

    async def execute(
        self,
        conversation_id: int,
        tool_request: Dict[str, Any],
    ) -> Tuple[bool, Optional[str]]:
        """Execute a tool and submit the result. Returns (success, output_preview)."""
        tool_name = tool_request.get("name")
        tool_args = tool_request.get("arguments", {})
        call_id = tool_request.get("call_id")

        if not tool_name or not call_id:
            return False, None

        self._msg(f"[yellow]Executing tool:[/yellow] {tool_name}")

        if tool_name not in self.tools:
            error_msg = f"Unknown tool: {tool_name}"
            self._msg(f"[red]{error_msg}[/red]")
            await self._submit_result(conversation_id, call_id, False, None, f"[Tool failed: {error_msg}]")
            return False, None

        try:
            tool = self.tools[tool_name]
            loop = asyncio.get_event_loop()
            success, output, error = await loop.run_in_executor(
                None,
                tool.execute,
                tool_args,
            )

            if output:
                preview = output[:200] + ("..." if len(output) > 200 else "")
                self._msg(f"[dim]Output: {preview}[/dim]")
            if error:
                self._msg(f"[red]Error: {error[:100]}[/red]")

            formatted_error = f"[Tool failed: {error}]" if not success and error else error
            await self._submit_result(conversation_id, call_id, success, output, formatted_error)

            return success, output[:200] if output else None

        except Exception as e:
            error_msg = str(e)
            self._msg(f"[red]Tool execution failed: {error_msg}[/red]")
            await self._submit_result(conversation_id, call_id, False, None, f"[Tool failed: {error_msg}]")
            return False, None

    async def reject(self, conversation_id: int, tool_request: Dict[str, Any]) -> None:
        call_id = tool_request.get("call_id")
        if call_id:
            self._msg("[yellow]Tool execution rejected.[/yellow]")
            await self._submit_result(conversation_id, call_id, False, None, "[User refused tool execution]")

    async def _submit_result(
        self,
        conversation_id: int,
        call_id: str,
        success: bool,
        output: Optional[str],
        error: Optional[str],
    ) -> None:
        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                lambda: self.client.submit_tool_result(
                    conversation_id,
                    call_id,
                    success,
                    output,
                    error,
                ),
            )
        except Exception as e:
            self._msg(f"[red]Failed to submit tool result: {e}[/red]")
