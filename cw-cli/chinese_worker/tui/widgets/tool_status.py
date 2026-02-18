"""Widget for displaying tool execution status and results."""

from typing import Optional

from textual.app import ComposeResult
from textual.reactive import reactive
from textual.widgets import Static


class ToolStatusWidget(Static):
    """Shows a tool's lifecycle: executing -> completed/failed."""

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
            return f"[#fab387]\u25b6[/#fab387] [bold]{self.tool_name}[/bold] [#7f849c]running...[/#7f849c]"
        elif self.status == "completed" and self.success:
            preview = self._truncate(self.result_content, 200)
            result = f"[#a6e3a1]\u2713[/#a6e3a1] [bold]{self.tool_name}[/bold]"
            if preview:
                result += f"\n[#7f849c]{preview}[/#7f849c]"
            return result
        elif self.status == "completed" and not self.success:
            preview = self._truncate(self.result_content, 200)
            result = f"[#f38ba8]\u2717[/#f38ba8] [bold]{self.tool_name}[/bold] [#f38ba8]failed[/#f38ba8]"
            if preview:
                result += f"\n[#7f849c]{preview}[/#7f849c]"
            return result
        return f"[#7f849c]{self.tool_name}[/#7f849c]"

    def complete(self, success: bool, content: str = "") -> None:
        self.success = success
        self.result_content = content
        self.status = "completed"
        if success:
            self.add_class("-success-border")
        else:
            self.add_class("-error-border")

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
