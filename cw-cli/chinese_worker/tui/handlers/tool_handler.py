"""Tool execution handler for TUI."""

import asyncio
from typing import Dict, Any, List, TYPE_CHECKING

from ...tools.base import BaseTool

if TYPE_CHECKING:
    from ...api import APIClient
    from ..screens.chat import ChatScreen


class ToolHandler:
    """Handler for tool execution in the TUI."""

    def __init__(
        self,
        tools: Dict[str, BaseTool],
        client: "APIClient",
        screen: "ChatScreen",
    ) -> None:
        self.tools = tools
        self.client = client
        self.screen = screen

    def get_tool_names(self) -> List[str]:
        """Get list of available tool names."""
        return list(self.tools.keys())

    async def execute(self, tool_request: Dict[str, Any]) -> bool:
        """
        Execute a tool request.

        Returns:
            True if execution succeeded, False otherwise.
        """
        tool_name = tool_request.get("name")
        tool_args = tool_request.get("arguments", {})
        call_id = tool_request.get("call_id")

        if not tool_name or not call_id:
            return False

        # Show tool execution message
        self.screen.add_system_message(f"[yellow]Executing tool:[/yellow] {tool_name}")

        if tool_name not in self.tools:
            # Unknown tool
            error_msg = f"Unknown tool: {tool_name}"
            self.screen.add_system_message(f"[red]{error_msg}[/red]")
            await self._submit_result(call_id, False, None, f"[Tool failed: {error_msg}]")
            return False

        try:
            # Execute the tool (run in executor to avoid blocking)
            tool = self.tools[tool_name]
            loop = asyncio.get_event_loop()
            success, output, error = await loop.run_in_executor(
                None,
                tool.execute,
                tool_args,
            )

            # Show output preview
            if output:
                preview = output[:200] + ("..." if len(output) > 200 else "")
                self.screen.add_system_message(f"[dim]Output: {preview}[/dim]")
            if error:
                self.screen.add_system_message(f"[red]Error: {error[:100]}[/red]")

            # Submit result
            formatted_error = f"[Tool failed: {error}]" if not success and error else error
            await self._submit_result(call_id, success, output, formatted_error)

            return success

        except Exception as e:
            error_msg = str(e)
            self.screen.add_system_message(f"[red]Tool execution failed: {error_msg}[/red]")
            await self._submit_result(call_id, False, None, f"[Tool failed: {error_msg}]")
            return False

    async def reject(self, tool_request: Dict[str, Any]) -> None:
        """Reject a tool request."""
        call_id = tool_request.get("call_id")
        if call_id:
            self.screen.add_system_message("[yellow]Tool execution rejected.[/yellow]")
            await self._submit_result(call_id, False, None, "[User refused tool execution]")

    async def _submit_result(
        self,
        call_id: str,
        success: bool,
        output: str | None,
        error: str | None,
    ) -> None:
        """Submit tool result to server."""
        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                lambda: self.client.submit_tool_result(
                    self.screen.conversation_id,
                    call_id,
                    success,
                    output,
                    error,
                ),
            )
        except Exception as e:
            self.screen.add_system_message(f"[red]Failed to submit tool result: {str(e)}[/red]")
