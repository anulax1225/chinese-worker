"""Main TUI application."""

import os
from typing import Optional, Dict, Any, List

from textual.app import App
from textual.binding import Binding

from ..api import APIClient, AuthManager
from ..tools.base import BaseTool


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


class CWApp(App):
    """Chinese Worker TUI Application."""

    TITLE = "Chinese Worker"
    CSS_PATH = [
        "styles/theme.tcss",
        "styles/login.tcss",
        "styles/home.tcss",
        "styles/chat.tcss",
    ]

    BINDINGS = [
        Binding("ctrl+q", "quit", "Quit", show=True),
        Binding("ctrl+c", "stop", "Stop", show=True),
    ]

    def __init__(self) -> None:
        super().__init__()
        self.api_url = get_default_api_url()
        self.client: Optional[APIClient] = None
        self.current_agent: Optional[Dict[str, Any]] = None
        self.current_conversation: Optional[Dict[str, Any]] = None
        self.auto_approve_tools = False
        self._tools: Optional[Dict[str, BaseTool]] = None
        self._tool_schemas: Optional[List[Dict[str, Any]]] = None
        self._client_type: Optional[str] = None

    async def on_mount(self) -> None:
        """Called when app is mounted."""
        self.client = APIClient(self.api_url)

        # Initialize tools once at startup
        from ..cli import get_platform_tools, get_tool_schemas, get_client_type
        self._tools = get_platform_tools()
        self._tool_schemas = get_tool_schemas(self._tools)
        self._client_type = get_client_type()

        # Navigate based on auth state
        if AuthManager.is_authenticated():
            from .screens import HomeScreen
            await self.push_screen(HomeScreen())
        else:
            from .screens import LoginScreen
            await self.push_screen(LoginScreen())

    async def action_quit(self) -> None:
        """Quit the application."""
        self.exit()

    async def action_stop(self) -> None:
        """Stop current operation."""
        if hasattr(self.screen, "stop_operation"):
            await self.screen.stop_operation()
