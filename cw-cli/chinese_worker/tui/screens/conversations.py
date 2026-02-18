"""Conversation list screen for browsing all conversations."""

import asyncio
from typing import Optional, List, Dict, Any

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Container, Horizontal, VerticalScroll
from textual.screen import Screen
from textual.widgets import Button, Input, Select, Static

from ..widgets.conversation_item import ConversationItem


class ConversationListScreen(Screen):
    """Screen for browsing and managing conversations.

    Displays a list of all conversations (optionally filtered by agent)
    with status filtering and search capabilities. Users can resume,
    delete, or filter conversations using keyboard shortcuts.
    """

    BINDINGS = [
        Binding("escape", "back", "Back"),
        Binding("d", "delete", "Delete"),
        Binding("f", "filter", "Filter"),
        Binding("/", "search", "Search"),
        Binding("r", "refresh", "Refresh"),
    ]

    def __init__(self, agent_id: Optional[int] = None) -> None:
        super().__init__()
        self._agent_id = agent_id
        self._conversations: List[Dict[str, Any]] = []
        self._selected_idx = 0
        self._status_filter: Optional[str] = None
        self._search_query = ""

    def compose(self) -> ComposeResult:
        yield Container(
            Horizontal(
                Static("[bold]Conversations[/bold]", id="conv-title"),
                Select(
                    [
                        ("All", ""),
                        ("Active", "active"),
                        ("Completed", "completed"),
                        ("Failed", "failed"),
                        ("Paused", "paused"),
                        ("Cancelled", "cancelled"),
                    ],
                    value="",
                    id="status-filter",
                    allow_blank=False,
                ),
                Button("✕", variant="default", id="btn-close"),
                id="conv-header",
            ),
            Input(placeholder="Search conversations...", id="search-input"),
            Static("[#7f849c]Loading...[/#7f849c]", id="loading"),
            VerticalScroll(id="conv-list"),
            Static(
                "[dim][bold]Enter[/bold] resume  [bold]D[/bold] delete  "
                "[bold]F[/bold] filter  [bold]/[/bold] search  [bold]Esc[/bold] back[/dim]",
                id="conv-help",
            ),
            id="conv-container",
        )

    async def on_mount(self) -> None:
        asyncio.create_task(self._load_conversations())

    async def _load_conversations(self) -> None:
        """Fetch conversations from API and display them."""
        loading = self.query_one("#loading", Static)
        conv_list = self.query_one("#conv-list", VerticalScroll)

        loading.display = True
        conv_list.remove_children()

        try:
            loop = asyncio.get_event_loop()
            self._conversations = await loop.run_in_executor(
                None,
                lambda: self.app.client.list_conversations(
                    agent_id=self._agent_id,
                    status=self._status_filter if self._status_filter else None,
                    per_page=50,
                ),
            )

            loading.display = False

            # Filter by search if active
            filtered = self._filter_conversations()

            if not filtered:
                conv_list.mount(
                    Static("[#7f849c]No conversations found.[/#7f849c]", id="empty-msg")
                )
                return

            for i, conv in enumerate(filtered):
                item = ConversationItem(conv)
                item.id = f"conv-item-{conv['id']}"
                if i == 0:
                    item.mark_selected(True)
                conv_list.mount(item)

            self._selected_idx = 0

        except Exception as e:
            loading.update(f"[#f38ba8]Error: {e}[/#f38ba8]")

    def _filter_conversations(self) -> List[Dict[str, Any]]:
        """Filter conversations by search query (client-side).

        Returns:
            Filtered list of conversations matching the search query
        """
        if not self._search_query:
            return self._conversations

        query = self._search_query.lower()
        filtered = []

        for conv in self._conversations:
            # Search in first user message
            messages = conv.get("messages", [])
            matched = False
            for msg in messages:
                if msg.get("role") == "user":
                    if query in msg.get("content", "").lower():
                        matched = True
                        break

            if matched:
                filtered.append(conv)
                continue

            # Search in agent name
            agent = conv.get("agent", {})
            if isinstance(agent, dict):
                agent_name = agent.get("name", "").lower()
            else:
                agent_name = ""

            if query in agent_name:
                filtered.append(conv)
                continue

            # Search in conversation ID
            if query in str(conv.get("id", "")):
                filtered.append(conv)

        return filtered

    async def on_key(self, event) -> None:
        """Handle keyboard navigation."""
        # Don't handle keys when input has focus
        focused = self.app.focused
        if isinstance(focused, Input):
            return

        items = list(self.query(".conversation-item"))
        if not items:
            return

        if event.key in ("down", "j"):
            self._select_item(min(self._selected_idx + 1, len(items) - 1))
            event.stop()
        elif event.key in ("up", "k"):
            self._select_item(max(self._selected_idx - 1, 0))
            event.stop()
        elif event.key == "enter":
            await self._resume_selected()
            event.stop()

    def _select_item(self, idx: int) -> None:
        """Update visual selection to the given index."""
        items = list(self.query(".conversation-item"))
        for i, item in enumerate(items):
            if isinstance(item, ConversationItem):
                item.mark_selected(i == idx)
        self._selected_idx = idx

        # Scroll selected item into view
        if 0 <= idx < len(items):
            items[idx].scroll_visible()

    async def _resume_selected(self) -> None:
        """Resume the currently selected conversation."""
        items = list(self.query(".conversation-item"))
        if 0 <= self._selected_idx < len(items):
            item = items[self._selected_idx]
            if isinstance(item, ConversationItem):
                await self._open_conversation(item.conversation)

    async def _open_conversation(self, conversation: Dict[str, Any]) -> None:
        """Open/resume the selected conversation.

        Args:
            conversation: Conversation data dict (may be partial)
        """
        agent_id = conversation.get("agent_id")

        try:
            loop = asyncio.get_event_loop()

            # Fetch full conversation with messages
            full_conv = await loop.run_in_executor(
                None,
                self.app.client.get_conversation,
                conversation["id"],
            )

            # Get agent data
            agent = await loop.run_in_executor(
                None,
                self.app.client.get_agent,
                agent_id,
            )

            # Pop this screen and push chat with the conversation
            from .chat import ChatScreen

            self.app.pop_screen()
            self.app.push_screen(ChatScreen(agent, conversation=full_conv))

        except Exception as e:
            self.notify(f"Error: {e}", severity="error")

    async def on_select_changed(self, event: Select.Changed) -> None:
        """Handle status filter change."""
        if event.select.id == "status-filter":
            self._status_filter = event.value if event.value else None
            await self._load_conversations()

    async def on_input_submitted(self, event: Input.Submitted) -> None:
        """Handle search submission."""
        if event.input.id == "search-input":
            self._search_query = event.input.value.strip()
            await self._load_conversations()

    async def on_conversation_item_selected(
        self, event: ConversationItem.Selected
    ) -> None:
        """Handle conversation item click."""
        await self._open_conversation(event.conversation)

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle close button."""
        if event.button.id == "btn-close":
            self.app.pop_screen()

    async def action_back(self) -> None:
        """Go back to previous screen."""
        self.app.pop_screen()

    async def action_delete(self) -> None:
        """Delete the currently selected conversation."""
        items = list(self.query(".conversation-item"))
        if not (0 <= self._selected_idx < len(items)):
            return

        item = items[self._selected_idx]
        if not isinstance(item, ConversationItem):
            return

        conv_id = item.conversation.get("id")
        if not conv_id:
            return

        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.delete_conversation,
                conv_id,
            )
            self.notify(f"Deleted conversation #{conv_id}")
            await self._load_conversations()
        except Exception as e:
            self.notify(f"Error: {e}", severity="error")

    async def action_filter(self) -> None:
        """Focus the status filter."""
        self.query_one("#status-filter", Select).focus()

    async def action_search(self) -> None:
        """Focus the search input."""
        self.query_one("#search-input", Input).focus()

    async def action_refresh(self) -> None:
        """Refresh the conversation list."""
        await self._load_conversations()
