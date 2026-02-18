"""Home screen — agent selection."""

import asyncio
from typing import Optional, Dict, Any, List

from textual.app import ComposeResult
from textual.containers import Container, VerticalScroll
from textual.screen import Screen
from textual.widgets import Static
from textual.binding import Binding


class AgentCard(Static):
    """Clickable agent card."""

    def __init__(self, agent: Dict[str, Any]) -> None:
        self.agent = agent
        super().__init__()

    def compose(self) -> ComposeResult:
        name = self.agent.get("name", "Unknown")
        model = self.agent.get("model", "N/A")
        backend = self.agent.get("ai_backend", "N/A")
        tools_count = len(self.agent.get("tools", []))
        description = self.agent.get("description", "")

        content = f"[bold]{name}[/bold]\n"
        content += f"[#7f849c]{backend}[/#7f849c] / [#89dceb]{model}[/#89dceb]\n"
        if description:
            truncated = description[:60] + ("..." if len(description) > 60 else "")
            content += f"[#7f849c italic]{truncated}[/#7f849c italic]\n"
        content += f"[#7f849c]Tools: {tools_count}[/#7f849c]"

        yield Static(content)


class HomeScreen(Screen):
    """Screen for selecting an agent to chat with."""

    BINDINGS = [
        Binding("escape", "quit", "Quit"),
        Binding("r", "refresh", "Refresh"),
    ]

    def __init__(self) -> None:
        super().__init__()
        self.agents: List[Dict[str, Any]] = []
        self.selected_agent: Optional[Dict[str, Any]] = None

    def compose(self) -> ComposeResult:
        yield Container(
            Static("[bold]Select an Agent[/bold]", id="home-header"),
            Static("[dim]Loading agents...[/dim]", id="loading"),
            VerticalScroll(id="agent-list"),
            Static(
                "[dim][bold]Enter[/bold] select  [bold]j/k[/bold] navigate  [bold]r[/bold] refresh[/dim]",
                id="home-help",
            ),
            id="home-container",
        )

    async def on_mount(self) -> None:
        asyncio.create_task(self._load_agents())

    async def _load_agents(self) -> None:
        loading = self.query_one("#loading", Static)
        agent_list = self.query_one("#agent-list", VerticalScroll)

        loading.update("[#7f849c]Loading agents...[/#7f849c]")
        loading.display = True
        agent_list.remove_children()

        try:
            loop = asyncio.get_event_loop()
            self.agents = await loop.run_in_executor(
                None,
                self.app.client.list_agents,
            )

            if not self.agents:
                loading.update("[#f9e2af]No agents found. Create one in the web app.[/#f9e2af]")
                return

            loading.display = False

            for i, agent in enumerate(self.agents):
                card = AgentCard(agent)
                card.id = f"agent-{i}"
                card.add_class("agent-card")
                if i == 0:
                    card.add_class("selected")
                    self.selected_agent = agent
                agent_list.mount(card)

        except Exception as e:
            loading.update(f"[#f38ba8]Failed to load agents: {e}[/#f38ba8]")

    def on_click(self, event) -> None:
        for widget in self.query(".agent-card"):
            if widget.region.contains(event.x, event.y):
                self._select_card(widget)
                break

    def _select_card(self, card: AgentCard) -> None:
        for c in self.query(".agent-card"):
            c.remove_class("selected")
        card.add_class("selected")
        self.selected_agent = card.agent

    async def on_key(self, event) -> None:
        cards = list(self.query(".agent-card"))
        if not cards:
            return

        current_idx = 0
        for i, card in enumerate(cards):
            if card.has_class("selected"):
                current_idx = i
                break

        if event.key in ("down", "j"):
            new_idx = min(current_idx + 1, len(cards) - 1)
            self._select_card(cards[new_idx])
        elif event.key in ("up", "k"):
            new_idx = max(current_idx - 1, 0)
            self._select_card(cards[new_idx])
        elif event.key == "enter":
            await self._open_chat()

    async def _open_chat(self) -> None:
        if self.selected_agent:
            self.app.current_agent = self.selected_agent
            from .chat import ChatScreen
            self.app.push_screen(ChatScreen(self.selected_agent))

    async def action_refresh(self) -> None:
        await self._load_agents()

    def action_quit(self) -> None:
        self.app.exit()
