"""Tool approval modal widget."""

from typing import Dict, Any

from textual.app import ComposeResult
from textual.containers import Container, Vertical
from textual.screen import ModalScreen
from textual.widgets import Button, Static
from textual.binding import Binding


class ToolApprovalModal(ModalScreen[str]):
    """Modal dialog for tool execution approval."""

    BINDINGS = [
        Binding("y", "approve", "Yes"),
        Binding("n", "reject", "No"),
        Binding("a", "approve_all", "All"),
        Binding("escape", "reject", "Cancel"),
    ]

    def __init__(self, tool_request: Dict[str, Any]) -> None:
        super().__init__()
        self.tool_request = tool_request

    def compose(self) -> ComposeResult:
        """Create modal content."""
        tool_name = self.tool_request.get("name", "unknown")
        tool_args = self.tool_request.get("arguments", {})

        # Build args display
        args_display = self._format_args(tool_name, tool_args)

        yield Container(
            Static("[bold]Tool Execution Request[/bold]", id="modal-title"),
            Vertical(
                Static(f"[bold cyan]Tool:[/bold cyan] {tool_name}"),
                Static(args_display, id="tool-args"),
                id="tool-details",
            ),
            Container(
                Button("[Y]es", variant="success", id="btn-yes"),
                Button("[N]o", variant="error", id="btn-no"),
                Button("[A]ll", variant="warning", id="btn-all"),
                id="modal-buttons",
            ),
            Static("[dim]Press Y to approve, N to reject, A to approve all future tools[/dim]", id="modal-help"),
            id="tool-approval-modal",
        )

    def _format_args(self, tool_name: str, args: Dict[str, Any]) -> str:
        """Format tool arguments for display."""
        if tool_name == "bash":
            command = args.get("command", "")
            return f"[yellow]$ {command}[/yellow]"
        elif tool_name == "read":
            return f"[dim]file:[/dim] {args.get('file_path', '')}"
        elif tool_name == "write":
            content = args.get("content", "")
            preview = content[:100] + ("..." if len(content) > 100 else "")
            return f"[dim]file:[/dim] {args.get('file_path', '')}\n[dim]content:[/dim] {preview}"
        elif tool_name == "edit":
            return (
                f"[dim]file:[/dim] {args.get('file_path', '')}\n"
                f"[dim]old:[/dim] {args.get('old_string', '')[:50]}...\n"
                f"[dim]new:[/dim] {args.get('new_string', '')[:50]}..."
            )
        elif tool_name in ("glob", "grep"):
            return f"[dim]pattern:[/dim] {args.get('pattern', '')}"
        else:
            # Generic display
            lines = []
            for key, value in args.items():
                lines.append(f"[dim]{key}:[/dim] {str(value)[:80]}")
            return "\n".join(lines)

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle button press."""
        if event.button.id == "btn-yes":
            self.dismiss("yes")
        elif event.button.id == "btn-no":
            self.dismiss("no")
        elif event.button.id == "btn-all":
            self.dismiss("all")

    def action_approve(self) -> None:
        """Approve tool execution."""
        self.dismiss("yes")

    def action_reject(self) -> None:
        """Reject tool execution."""
        self.dismiss("no")

    def action_approve_all(self) -> None:
        """Approve all future tools."""
        self.dismiss("all")
