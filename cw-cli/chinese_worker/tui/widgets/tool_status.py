"""Widget for displaying tool execution status and results."""

from typing import Optional

from textual.app import ComposeResult
from textual.reactive import reactive
from textual.widgets import Static


class ToolStatusWidget(Static):
    """Shows a tool's lifecycle: executing → completed/failed."""

    tool_name: reactive[str] = reactive("")
    call_id: reactive[str] = reactive("")
    status: reactive[str] = reactive("executing")
    result_content: reactive[str] = reactive("")
    success: reactive[bool] = reactive(True)

    def __init__(
        self,
        tool_name: str,
        call_id: str = "",
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self.tool_name = tool_name
        self.call_id = call_id
        self.add_class("tool-status")

    def render(self) -> str:
        if self.status == "executing":
            return f"[yellow]\u25b6[/yellow] [bold]{self.tool_name}[/bold] [dim]running...[/dim]"
        elif self.status == "completed" and self.success:
            preview = self._truncate(self.result_content, 200)
            result = f"[green]\u2713[/green] [bold]{self.tool_name}[/bold]"
            if preview:
                result += f"\n[dim]{preview}[/dim]"
            return result
        elif self.status == "completed" and not self.success:
            preview = self._truncate(self.result_content, 200)
            result = f"[red]\u2717[/red] [bold]{self.tool_name}[/bold] [red]failed[/red]"
            if preview:
                result += f"\n[dim]{preview}[/dim]"
            return result
        return f"[dim]{self.tool_name}[/dim]"

    def complete(self, success: bool, content: str = "") -> None:
        self.success = success
        self.result_content = content
        self.status = "completed"

    @staticmethod
    def _truncate(text: str, max_len: int) -> str:
        if not text:
            return ""
        # Take first few lines only
        lines = text.strip().splitlines()
        preview = "\n".join(lines[:5])
        if len(lines) > 5:
            preview += f"\n... ({len(lines) - 5} more lines)"
        if len(preview) > max_len:
            preview = preview[:max_len] + "..."
        return preview
