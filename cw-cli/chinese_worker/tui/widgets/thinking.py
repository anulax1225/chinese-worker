"""Thinking block widget for displaying AI reasoning."""

from textual.app import ComposeResult
from textual.widgets import Collapsible, Static


class ThinkingBlock(Collapsible):
    """Collapsible thinking/reasoning display."""

    def __init__(self, content: str = "", **kwargs) -> None:
        super().__init__(title="Thinking...", collapsed=True, **kwargs)
        self._content = content
        self.add_class("thinking-block")

    def compose(self) -> ComposeResult:
        yield Static(self._content, id="thinking-content", classes="thinking-text")

    def update_content(self, content: str) -> None:
        self._content = content
        try:
            self.query_one("#thinking-content", Static).update(content)
        except Exception:
            pass

    def finalize(self) -> None:
        word_count = len(self._content.split())
        self.title = f"Thinking ({word_count} words)"
