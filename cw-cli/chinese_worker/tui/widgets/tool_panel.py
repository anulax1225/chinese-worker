"""Inline tool approval panel."""

from typing import Dict, Any

from textual.app import ComposeResult
from textual.containers import Horizontal
from textual.message import Message
from textual.widgets import Button, Static
from textual.binding import Binding


class ToolApprovalPanel(Static):
    """Inline tool approval panel (appears in message list)."""

    BINDINGS = [
        Binding("y", "approve", "Yes", show=False),
        Binding("n", "reject", "No", show=False),
        Binding("a", "approve_all", "All", show=False),
    ]

    class Approved(Message):
        def __init__(self, tool_request: Dict[str, Any]) -> None:
            self.tool_request = tool_request
            super().__init__()

    class Rejected(Message):
        def __init__(self, tool_request: Dict[str, Any]) -> None:
            self.tool_request = tool_request
            super().__init__()

    class ApproveAll(Message):
        def __init__(self, tool_request: Dict[str, Any]) -> None:
            self.tool_request = tool_request
            super().__init__()

    def __init__(self, tool_request: Dict[str, Any], **kwargs) -> None:
        super().__init__(**kwargs)
        self.tool_request = tool_request
        self.can_focus = True
        self.add_class("tool-panel")

    def compose(self) -> ComposeResult:
        tool_name = self.tool_request.get("name", "unknown")
        tool_args = self.tool_request.get("arguments", {})
        args_display = self._format_args(tool_name, tool_args)

        yield Static(
            f"[bold #fab387]Tool Request:[/bold #fab387] [#89dceb]{tool_name}[/#89dceb]",
            id="tool-header",
        )
        yield Static(args_display, id="tool-args")
        yield Horizontal(
            Button("[Y]es", variant="success", id="btn-yes"),
            Button("[N]o", variant="error", id="btn-no"),
            Button("[A]ll", variant="warning", id="btn-all"),
            id="tool-buttons",
        )
        yield Static(
            "[#6c7086]Y: approve  N: reject  A: approve all future[/#6c7086]",
            id="tool-help",
        )

    def _format_args(self, tool_name: str, args: Dict[str, Any]) -> str:
        if tool_name == "bash":
            return f"[#f9e2af]$ {args.get('command', '')}[/#f9e2af]"
        elif tool_name == "read":
            return f"[#7f849c]file:[/#7f849c] {args.get('file_path', '')}"
        elif tool_name == "write":
            content = args.get("content", "")
            preview = content[:100] + ("..." if len(content) > 100 else "")
            return f"[#7f849c]file:[/#7f849c] {args.get('file_path', '')}\n[#7f849c]content:[/#7f849c] {preview}"
        elif tool_name == "edit":
            old_str = args.get("old_string", "")[:50]
            new_str = args.get("new_string", "")[:50]
            return (
                f"[#7f849c]file:[/#7f849c] {args.get('file_path', '')}\n"
                f"[#7f849c]old:[/#7f849c] {old_str}{'...' if len(args.get('old_string', '')) > 50 else ''}\n"
                f"[#7f849c]new:[/#7f849c] {new_str}{'...' if len(args.get('new_string', '')) > 50 else ''}"
            )
        elif tool_name in ("glob", "grep"):
            return f"[#7f849c]pattern:[/#7f849c] {args.get('pattern', '')}"
        else:
            lines = []
            for key, value in args.items():
                lines.append(f"[#7f849c]{key}:[/#7f849c] {str(value)[:80]}")
            return "\n".join(lines) if lines else "[#7f849c]No arguments[/#7f849c]"

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        event.stop()
        if event.button.id == "btn-yes":
            self.post_message(self.Approved(self.tool_request))
        elif event.button.id == "btn-no":
            self.post_message(self.Rejected(self.tool_request))
        elif event.button.id == "btn-all":
            self.post_message(self.ApproveAll(self.tool_request))

    def action_approve(self) -> None:
        self.post_message(self.Approved(self.tool_request))

    def action_reject(self) -> None:
        self.post_message(self.Rejected(self.tool_request))

    def action_approve_all(self) -> None:
        self.post_message(self.ApproveAll(self.tool_request))
