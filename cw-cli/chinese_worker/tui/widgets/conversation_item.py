"""Conversation item widget for lists and sidebar."""

from typing import Dict, Any

from textual.app import ComposeResult
from textual.binding import Binding
from textual.message import Message
from textual.widgets import Static

from .status_badge import StatusBadge
from ..utils.time import relative_time


class ConversationItem(Static):
    """Single conversation row with metadata.

    Displays conversation information in either full mode (for list screens)
    or compact mode (for sidebar). Posts a Selected message when clicked
    or selected via keyboard.
    """

    BINDINGS = [
        Binding("enter", "select", "Select", show=False),
    ]

    DEFAULT_CSS = """
    ConversationItem {
        height: auto;
        padding: 1 2;
        border: solid $surface;
    }

    ConversationItem:hover {
        background: $surface;
    }

    ConversationItem:focus {
        border: solid $primary;
    }

    ConversationItem.selected {
        background: $surface;
        border: solid $primary;
    }

    ConversationItem.active {
        border-left: thick $success;
    }

    ConversationItem.-compact {
        padding: 0 1;
        border: none;
        margin: 0 0 1 0;
    }

    ConversationItem.-compact:hover {
        background: $panel;
    }

    ConversationItem.-compact.active {
        background: $surface;
        border-left: thick $primary;
    }
    """

    class Selected(Message):
        """Posted when conversation is selected."""

        def __init__(self, conversation: Dict[str, Any]) -> None:
            self.conversation = conversation
            super().__init__()

    def __init__(
        self,
        conversation: Dict[str, Any],
        compact: bool = False,
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self.conversation = conversation
        self._compact = compact
        self.can_focus = True
        self.add_class("conversation-item")
        if compact:
            self.add_class("-compact")

    def compose(self) -> ComposeResult:
        conv = self.conversation
        conv_id = conv.get("id", "?")
        msg_count = conv.get("message_count", len(conv.get("messages", [])))
        last_activity = relative_time(conv.get("updated_at"))
        status = conv.get("status", "active")

        # Get agent name (may be nested or flat depending on API response)
        agent = conv.get("agent", {})
        if isinstance(agent, dict):
            agent_name = agent.get("name", "Unknown")
        else:
            agent_name = str(agent) if agent else "Unknown"

        # First message preview
        messages = conv.get("messages", [])
        preview = ""
        for msg in messages:
            if msg.get("role") == "user":
                content = msg.get("content", "")
                preview = content[:50] + ("..." if len(content) > 50 else "")
                break

        if self._compact:
            # Sidebar compact view
            yield Static(
                f"[bold]#{conv_id}[/bold] ({status})\n"
                f"[#7f849c]{msg_count} msgs, {last_activity}[/#7f849c]",
                id="conv-content",
            )
        else:
            # Full list view with header, status badge, and preview
            yield Static(
                f"[bold]#{conv_id}[/bold]  {agent_name}  "
                f"[#7f849c]{msg_count} msgs   {last_activity}[/#7f849c]",
                id="conv-header",
            )
            yield StatusBadge(status, id="conv-status")
            if preview:
                yield Static(
                    f'[#6c7086 italic]"{preview}"[/#6c7086 italic]',
                    id="conv-preview",
                )

    async def on_click(self) -> None:
        """Handle click events."""
        self.post_message(self.Selected(self.conversation))

    def action_select(self) -> None:
        """Handle enter key selection."""
        self.post_message(self.Selected(self.conversation))

    def mark_active(self, is_active: bool = True) -> None:
        """Mark this item as the currently active conversation.

        Args:
            is_active: Whether this is the active conversation
        """
        if is_active:
            self.add_class("active")
        else:
            self.remove_class("active")

    def mark_selected(self, is_selected: bool = True) -> None:
        """Mark this item as visually selected in the list.

        Args:
            is_selected: Whether this item is selected
        """
        if is_selected:
            self.add_class("selected")
        else:
            self.remove_class("selected")
