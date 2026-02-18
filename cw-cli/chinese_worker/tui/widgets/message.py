"""Chat message widget."""

from textual.app import ComposeResult
from textual.widgets import Static, Markdown


class ChatMessage(Static):
    """Widget for displaying a single chat message."""

    def __init__(
        self,
        content: str,
        role: str = "user",
        streaming: bool = False,
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self._content = content
        self._role = role
        self._streaming = streaming
        self.add_class(f"message-{role}")
        if streaming:
            self.add_class("streaming")

    def compose(self) -> ComposeResult:
        if self._role == "assistant":
            yield Static("[bold green]Assistant:[/bold green]", id="message-prefix")
            initial = "" if self._streaming and not self._content else self._content
            yield Markdown(initial, id="message-content")
        else:
            yield Static(self._render_content(), id="message-content")

    def _render_content(self) -> str:
        if self._role == "user":
            return f"[bold cyan]You:[/bold cyan] {self._content}"
        elif self._role == "system":
            return f"[dim]{self._content}[/dim]"
        elif self._role == "error":
            return f"[red]{self._content}[/red]"
        elif self._role == "tool":
            return f"[yellow]Tool:[/yellow] {self._content}"
        return self._content

    def update_content(self, content: str) -> None:
        self._content = content
        if self._role == "assistant":
            self.query_one("#message-content", Markdown).update(content)
        else:
            self.query_one("#message-content", Static).update(self._render_content())

    def set_streaming(self, streaming: bool) -> None:
        self._streaming = streaming
        if streaming:
            self.add_class("streaming")
        else:
            self.remove_class("streaming")

    def get_markdown_widget(self) -> Markdown:
        """Return the Markdown widget for use with Markdown.get_stream()."""
        if self._role != "assistant":
            raise ValueError("get_markdown_widget() is only valid for assistant messages")
        return self.query_one("#message-content", Markdown)
