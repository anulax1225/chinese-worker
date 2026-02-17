"""Message widget for displaying chat messages."""

from textual.app import ComposeResult
from textual.widgets import Static, Markdown


class MessageWidget(Static):
    """Widget for displaying a single chat message."""

    def __init__(
        self,
        content: str,
        role: str = "user",
        streaming: bool = False,
        **kwargs
    ) -> None:
        super().__init__(**kwargs)
        self._content = content
        self._role = role
        self._streaming = streaming
        self.add_class(f"message-{role}")
        if streaming:
            self.add_class("streaming")

    def compose(self) -> ComposeResult:
        """Create message content."""
        if self._role == "assistant":
            # Use Textual's Markdown widget for proper rendering
            yield Static("[bold green]Assistant:[/bold green]", id="message-prefix")
            initial = "" if self._streaming and not self._content else self._content
            yield Markdown(initial, id="message-content")
        else:
            yield Static(self._render_content(), id="message-content")

    def _render_content(self) -> str:
        """Render the message content for non-assistant messages."""
        if self._role == "user":
            return f"[bold cyan]You:[/bold cyan] {self._content}"
        elif self._role == "thinking":
            return f"[dim italic]💭 {self._content}[/dim italic]"
        elif self._role == "system":
            return f"[dim]{self._content}[/dim]"
        elif self._role == "error":
            return f"[red]{self._content}[/red]"
        elif self._role == "tool":
            return f"[yellow]Tool:[/yellow] {self._content}"
        else:
            return self._content

    def update_content(self, content: str) -> None:
        """Update the message content."""
        self._content = content

        if self._role == "assistant":
            # Update Markdown widget directly
            md_widget = self.query_one("#message-content", Markdown)
            md_widget.update(content)
        else:
            # Update Static widget
            msg_content = self.query_one("#message-content", Static)
            msg_content.update(self._render_content())

    def set_streaming(self, streaming: bool) -> None:
        """Set streaming state."""
        self._streaming = streaming
        if streaming:
            self.add_class("streaming")
        else:
            self.remove_class("streaming")
