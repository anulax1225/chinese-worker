"""Agent selection screen."""

import asyncio
from typing import Optional, Dict, Any, List

from textual.app import ComposeResult
from textual.containers import Container, Vertical, VerticalScroll
from textual.screen import Screen
from textual.widgets import Button, Label, ListItem, ListView, Static
from textual.binding import Binding


class AgentCard(Static):
    """Widget displaying an agent card."""

    def __init__(self, agent: Dict[str, Any]) -> None:
        self.agent = agent
        super().__init__()

    def compose(self) -> ComposeResult:
        """Create agent card content."""
        name = self.agent.get("name", "Unknown")
        model = self.agent.get("model", "N/A")
        backend = self.agent.get("ai_backend", "N/A")
        tools_count = len(self.agent.get("tools", []))
        description = self.agent.get("description", "")

        content = f"[bold]{name}[/bold]\n"
        content += f"[dim]{backend}[/dim] / [cyan]{model}[/cyan]\n"
        if description:
            content += f"[dim italic]{description[:50]}{'...' if len(description) > 50 else ''}[/dim italic]\n"
        content += f"[dim]Tools: {tools_count}[/dim]"

        yield Static(content, classes="agent-card-content")


class AgentSelectScreen(Screen):
    """Screen for selecting an agent."""

    BINDINGS = [
        Binding("escape", "quit", "Quit"),
        Binding("r", "refresh", "Refresh"),
        Binding("n", "new_conversation", "New Conv"),
    ]

    def __init__(self) -> None:
        super().__init__()
        self.agents: List[Dict[str, Any]] = []
        self.selected_agent: Optional[Dict[str, Any]] = None

    def compose(self) -> ComposeResult:
        """Create child widgets."""
        yield Container(
            Static("Select an Agent", id="screen-title"),
            Static("[dim]Loading agents...[/dim]", id="loading"),
            VerticalScroll(id="agent-list"),
            Static("[dim]Press [bold]Enter[/bold] to select, [bold]n[/bold] for new conversation, [bold]r[/bold] to refresh[/dim]", id="help-text"),
            id="agent-select-container",
        )

    async def on_mount(self) -> None:
        """Load agents when mounted."""
        # Load agents in background to not block UI
        asyncio.create_task(self.load_agents())

    async def load_agents(self) -> None:
        """Load agents from API."""
        loading = self.query_one("#loading", Static)
        agent_list = self.query_one("#agent-list", VerticalScroll)

        loading.update("[dim]Loading agents...[/dim]")
        loading.display = True
        agent_list.remove_children()

        try:
            # Run blocking API call in executor
            loop = asyncio.get_event_loop()
            self.agents = await loop.run_in_executor(
                None,
                self.app.client.list_agents,
            )

            if not self.agents:
                loading.update("[yellow]No agents found. Create one in the web app.[/yellow]")
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
            loading.update(f"[red]Failed to load agents: {str(e)}[/red]")

    def on_click(self, event) -> None:
        """Handle click on agent card."""
        # Find clicked agent card
        for widget in self.query(".agent-card"):
            if widget.region.contains(event.x, event.y):
                self._select_card(widget)
                break

    def _select_card(self, card: AgentCard) -> None:
        """Select an agent card."""
        # Remove selection from all cards
        for c in self.query(".agent-card"):
            c.remove_class("selected")

        # Select clicked card
        card.add_class("selected")
        self.selected_agent = card.agent

    async def on_key(self, event) -> None:
        """Handle key press."""
        cards = list(self.query(".agent-card"))
        if not cards:
            return

        current_idx = 0
        for i, card in enumerate(cards):
            if card.has_class("selected"):
                current_idx = i
                break

        if event.key == "down" or event.key == "j":
            # Move selection down
            new_idx = min(current_idx + 1, len(cards) - 1)
            self._select_card(cards[new_idx])
        elif event.key == "up" or event.key == "k":
            # Move selection up
            new_idx = max(current_idx - 1, 0)
            self._select_card(cards[new_idx])
        elif event.key == "enter":
            await self.select_agent()

    async def select_agent(self) -> None:
        """Select the current agent and move to chat."""
        if self.selected_agent:
            await self.app.switch_to_chat(self.selected_agent)

    async def action_refresh(self) -> None:
        """Refresh agent list."""
        await self.load_agents()

    async def action_new_conversation(self) -> None:
        """Start new conversation with selected agent."""
        if self.selected_agent:
            await self.app.switch_to_chat(self.selected_agent, None)

    def action_quit(self) -> None:
        """Quit the app."""
        self.app.exit()
