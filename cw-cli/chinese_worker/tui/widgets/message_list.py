"""Message list widget."""

from textual.containers import VerticalScroll


class MessageList(VerticalScroll):
    """Scrollable container for chat messages."""

    def __init__(self, **kwargs) -> None:
        super().__init__(**kwargs)
        self.can_focus = False
