"""Conversation sidebar for ChatScreen."""

import asyncio
from typing import Dict, Any, List, Optional

from textual.app import ComposeResult
from textual.containers import Container, VerticalScroll
from textual.message import Message
from textual.widgets import Button, Static

from .conversation_item import ConversationItem


class ConversationSidebar(Container):
    """Toggleable sidebar showing recent conversations for current agent.

    This widget displays a list of conversations for the selected agent,
    allowing quick switching between conversations without leaving the
    chat screen.
    """

    DEFAULT_CSS = """
    ConversationSidebar {
        width: 28;
        background: $surface;
        border-right: solid $panel;
        display: none;
        padding: 1;
    }

    ConversationSidebar.-visible {
        display: block;
    }

    ConversationSidebar #sidebar-title {
        text-align: center;
        margin-bottom: 1;
        color: $primary;
    }

    ConversationSidebar #btn-new-conv {
        width: 100%;
        margin-bottom: 1;
    }

    ConversationSidebar #sidebar-list {
        height: 1fr;
    }

    ConversationSidebar #sidebar-loading {
        text-align: center;
        color: $text-muted;
        padding: 1;
    }

    ConversationSidebar #sidebar-error {
        color: $error;
        padding: 1;
    }
    """

    class NewConversation(Message):
        """Request to start a new conversation."""

        pass

    class SwitchConversation(Message):
        """Request to switch to a different conversation."""

        def __init__(self, conversation_id: int) -> None:
            self.conversation_id = conversation_id
            super().__init__()

    def __init__(self, agent_id: Optional[int] = None, **kwargs) -> None:
        super().__init__(**kwargs)
        self._agent_id = agent_id
        self._current_conv_id: Optional[int] = None
        self._conversations: List[Dict[str, Any]] = []
        self.add_class("sidebar")

    def compose(self) -> ComposeResult:
        yield Static("[bold]Conversations[/bold]", id="sidebar-title")
        yield Button("+ New", variant="primary", id="btn-new-conv")
        yield VerticalScroll(id="sidebar-list")

    async def load_conversations(self) -> None:
        """Fetch and display conversations for current agent."""
        sidebar_list = self.query_one("#sidebar-list", VerticalScroll)
        sidebar_list.remove_children()

        # Show loading indicator
        loading = Static("[#7f849c]Loading...[/#7f849c]", id="sidebar-loading")
        sidebar_list.mount(loading)

        try:
            loop = asyncio.get_event_loop()
            self._conversations = await loop.run_in_executor(
                None,
                lambda: self.app.client.list_conversations(
                    agent_id=self._agent_id,
                    per_page=20,
                ),
            )

            # Remove loading indicator
            loading.remove()

            if not self._conversations:
                sidebar_list.mount(
                    Static("[#7f849c]No conversations yet[/#7f849c]", id="sidebar-empty")
                )
                return

            for conv in self._conversations:
                item = ConversationItem(conv, compact=True)
                item.id = f"sidebar-conv-{conv['id']}"
                if conv["id"] == self._current_conv_id:
                    item.mark_active(True)
                sidebar_list.mount(item)

        except Exception as e:
            loading.remove()
            sidebar_list.mount(
                Static(f"[#f38ba8]Error: {e}[/#f38ba8]", id="sidebar-error")
            )

    def set_agent(self, agent_id: int) -> None:
        """Update agent and reload conversations.

        Args:
            agent_id: ID of the agent to filter conversations by
        """
        self._agent_id = agent_id
        asyncio.create_task(self.load_conversations())

    def set_current_conversation(self, conv_id: Optional[int]) -> None:
        """Highlight the current conversation.

        Args:
            conv_id: ID of the currently active conversation
        """
        # Remove old highlight
        for item in self.query(".conversation-item"):
            if isinstance(item, ConversationItem):
                item.mark_active(False)

        self._current_conv_id = conv_id

        # Add new highlight
        if conv_id:
            try:
                item = self.query_one(f"#sidebar-conv-{conv_id}", ConversationItem)
                item.mark_active(True)
            except Exception:
                pass

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle new conversation button."""
        if event.button.id == "btn-new-conv":
            event.stop()
            self.post_message(self.NewConversation())

    async def on_conversation_item_selected(
        self, event: ConversationItem.Selected
    ) -> None:
        """Handle conversation selection from list."""
        event.stop()
        conv_id = event.conversation.get("id")
        if conv_id and conv_id != self._current_conv_id:
            self.post_message(self.SwitchConversation(conv_id))

    def toggle(self) -> bool:
        """Toggle sidebar visibility.

        Returns:
            True if sidebar is now visible, False otherwise
        """
        if self.has_class("-visible"):
            self.remove_class("-visible")
            return False
        else:
            self.add_class("-visible")
            asyncio.create_task(self.load_conversations())
            return True

    @property
    def is_visible(self) -> bool:
        """Check if sidebar is currently visible."""
        return self.has_class("-visible")
