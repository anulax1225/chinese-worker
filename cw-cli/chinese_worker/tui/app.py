"""Main TUI application."""

import os
from pathlib import Path
from typing import Optional, Dict, Any

from textual.app import App, ComposeResult
from textual.binding import Binding
from textual.screen import Screen

from ..api import APIClient, AuthManager


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


class CWApp(App):
    """Chinese Worker TUI Application."""

    TITLE = "Chinese Worker"
    CSS_PATH = "styles/app.tcss"

    BINDINGS = [
        Binding("ctrl+q", "quit", "Quit", show=True),
        Binding("ctrl+c", "stop", "Stop", show=True),
        Binding("escape", "back", "Back", show=False),
    ]

    def __init__(self) -> None:
        super().__init__()
        self.api_url = get_default_api_url()
        self.client: Optional[APIClient] = None
        self.current_agent: Optional[Dict[str, Any]] = None
        self.current_conversation: Optional[Dict[str, Any]] = None
        self.auto_approve_tools = False

    async def on_mount(self) -> None:
        """Called when app is mounted."""
        # Initialize API client
        self.client = APIClient(self.api_url)

        # Check authentication
        if not AuthManager.is_authenticated():
            from .screens import WelcomeScreen
            await self.push_screen(WelcomeScreen())
        else:
            from .screens import AgentSelectScreen
            await self.push_screen(AgentSelectScreen())

    async def action_quit(self) -> None:
        """Quit the application."""
        self.exit()

    async def action_stop(self) -> None:
        """Stop current operation."""
        # Notify current screen to stop any ongoing operation
        if hasattr(self.screen, "stop_operation"):
            await self.screen.stop_operation()

    async def action_back(self) -> None:
        """Go back to previous screen."""
        if len(self.screen_stack) > 1:
            self.pop_screen()

    async def switch_to_agent_select(self) -> None:
        """Switch to agent selection screen."""
        from .screens import AgentSelectScreen
        await self.switch_screen(AgentSelectScreen())

    async def switch_to_chat(self, agent: Dict[str, Any], conversation: Optional[Dict[str, Any]] = None) -> None:
        """Switch to chat screen with given agent."""
        from .screens import ChatScreen
        self.current_agent = agent
        self.current_conversation = conversation
        await self.switch_screen(ChatScreen(agent, conversation))

    async def on_login_success(self) -> None:
        """Handle successful login."""
        from .screens import AgentSelectScreen
        await self.switch_screen(AgentSelectScreen())
