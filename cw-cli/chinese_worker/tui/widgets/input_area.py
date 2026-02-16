"""Chat input widget."""

from textual.widgets import Input


class ChatInput(Input):
    """Input widget for chat messages with slash command support."""

    def __init__(self, **kwargs) -> None:
        super().__init__(**kwargs)
