"""Thinking block widget for displaying AI reasoning."""

from textual.app import ComposeResult
from textual.widgets import Collapsible, Static


class ThinkingBlock(Collapsible):
    """Collapsible thinking/reasoning display."""

    def __init__(self, content: str = "", **kwargs) -> None:
        super().__init__(title="💭 Thinking...", collapsed=True, **kwargs)
        self._content = content
        self.add_class("thinking-block")

    def compose(self) -> ComposeResult:
        """Create thinking content."""
        yield Static(self._content, id="thinking-content", classes="thinking-text")

    def update_content(self, content: str) -> None:
        """Update the thinking content."""
        self._content = content
        try:
            thinking_static = self.query_one("#thinking-content", Static)
            thinking_static.update(content)
        except Exception:
            # Widget may not be mounted yet
            pass

    def finalize(self) -> None:
        """Called when thinking is complete - update title."""
        word_count = len(self._content.split())
        self.title = f"💭 Thinking ({word_count} words)"
