"""Main TUI application."""

import os
from typing import Optional, Dict, Any, List

from textual.app import App
from textual.binding import Binding
from textual.theme import Theme

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
        self._register_catppuccin_mocha()
        self.api_url = get_default_api_url()
        self.client: Optional[APIClient] = None
        self.current_agent: Optional[Dict[str, Any]] = None
        self.current_conversation: Optional[Dict[str, Any]] = None
        self.auto_approve_tools = False
        self._tools: Optional[Dict[str, BaseTool]] = None
        self._tool_schemas: Optional[List[Dict[str, Any]]] = None
        self._client_type: Optional[str] = None

    def _register_catppuccin_mocha(self) -> None:
        """Register and activate the Catppuccin Mocha color theme."""
        self.register_theme(
            Theme(
                name="cw-catppuccin-mocha",
                primary="#cba6f7",
                secondary="#89b4fa",
                warning="#f9e2af",
                error="#f38ba8",
                success="#a6e3a1",
                accent="#fab387",
                foreground="#cdd6f4",
                background="#1e1e2e",
                surface="#313244",
                panel="#45475a",
                dark=True,
                variables={
                    "ctp-rosewater": "#f5e0dc",
                    "ctp-flamingo": "#f2cdcd",
                    "ctp-pink": "#f5c2e7",
                    "ctp-mauve": "#cba6f7",
                    "ctp-red": "#f38ba8",
                    "ctp-maroon": "#eba0ac",
                    "ctp-peach": "#fab387",
                    "ctp-yellow": "#f9e2af",
                    "ctp-green": "#a6e3a1",
                    "ctp-teal": "#94e2d5",
                    "ctp-sky": "#89dceb",
                    "ctp-sapphire": "#74c7ec",
                    "ctp-blue": "#89b4fa",
                    "ctp-lavender": "#b4befe",
                    "ctp-text": "#cdd6f4",
                    "ctp-subtext1": "#bac2de",
                    "ctp-subtext0": "#a6adc8",
                    "ctp-overlay2": "#9399b2",
                    "ctp-overlay1": "#7f849c",
                    "ctp-overlay0": "#6c7086",
                    "ctp-surface2": "#585b70",
                    "ctp-surface1": "#45475a",
                    "ctp-surface0": "#313244",
                    "ctp-base": "#1e1e2e",
                    "ctp-mantle": "#181825",
                    "ctp-crust": "#11111b",
                },
            )
        )
        self.theme = "cw-catppuccin-mocha"

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
