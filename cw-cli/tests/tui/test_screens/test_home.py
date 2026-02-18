"""Tests for HomeScreen."""

import pytest
from unittest.mock import MagicMock, patch

from textual.app import App, ComposeResult
from textual.containers import VerticalScroll
from textual.widgets import Static

from chinese_worker.tui.screens.home import HomeScreen, AgentCard


class HomeTestApp(App):
    """App for testing the HomeScreen."""

    def __init__(self, agents=None):
        super().__init__()
        self.client = MagicMock()
        self.client.list_agents.return_value = agents or []
        self.current_agent = None
        self._tools = {}
        self._tool_schemas = []
        self._client_type = "cli_linux"


class TestHomeScreenCompose:
    async def test_has_header(self):
        app = HomeTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            header = app.screen.query_one("#home-header", Static)
            rendered = str(header.render())
            assert "Select" in rendered or "Agent" in rendered

    async def test_has_agent_list_container(self):
        app = HomeTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            app.screen.query_one("#agent-list", VerticalScroll)

    async def test_has_help_footer(self):
        app = HomeTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            help_widget = app.screen.query_one("#home-help", Static)
            rendered = str(help_widget.render())
            assert "Enter" in rendered or "select" in rendered


class TestHomeScreenAgentLoading:
    async def test_loads_agents_on_mount(self):
        agents = [
            {"id": 1, "name": "Agent One", "model": "gpt-4", "ai_backend": "openai",
             "description": "First", "tools": ["bash"]},
            {"id": 2, "name": "Agent Two", "model": "claude-3", "ai_backend": "anthropic",
             "description": "Second", "tools": []},
        ]
        app = HomeTestApp(agents)
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            await pilot.pause()
            await pilot.pause()
            cards = list(app.screen.query(".agent-card"))
            assert len(cards) == 2

    async def test_first_agent_selected_by_default(self):
        agents = [
            {"id": 1, "name": "Agent One", "model": "gpt-4", "ai_backend": "openai",
             "description": "First", "tools": []},
            {"id": 2, "name": "Agent Two", "model": "claude-3", "ai_backend": "anthropic",
             "description": "Second", "tools": []},
        ]
        app = HomeTestApp(agents)
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            await pilot.pause()
            await pilot.pause()
            cards = list(app.screen.query(".agent-card"))
            assert cards[0].has_class("selected")
            assert not cards[1].has_class("selected")

    async def test_no_agents_shows_message(self):
        app = HomeTestApp(agents=[])
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            await pilot.pause()
            await pilot.pause()
            loading = app.screen.query_one("#loading", Static)
            rendered = str(loading.render())
            assert "No agents" in rendered or "no agents" in rendered.lower()


class TestHomeScreenNavigation:
    async def test_j_key_moves_selection_down(self):
        agents = [
            {"id": 1, "name": "A1", "model": "m1", "ai_backend": "b1", "description": "", "tools": []},
            {"id": 2, "name": "A2", "model": "m2", "ai_backend": "b2", "description": "", "tools": []},
        ]
        app = HomeTestApp(agents)
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            await pilot.pause()
            await pilot.pause()
            await pilot.press("j")
            await pilot.pause()
            cards = list(app.screen.query(".agent-card"))
            assert not cards[0].has_class("selected")
            assert cards[1].has_class("selected")

    async def test_k_key_moves_selection_up(self):
        agents = [
            {"id": 1, "name": "A1", "model": "m1", "ai_backend": "b1", "description": "", "tools": []},
            {"id": 2, "name": "A2", "model": "m2", "ai_backend": "b2", "description": "", "tools": []},
        ]
        app = HomeTestApp(agents)
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            await pilot.pause()
            await pilot.pause()
            await pilot.press("j")
            await pilot.press("k")
            await pilot.pause()
            cards = list(app.screen.query(".agent-card"))
            assert cards[0].has_class("selected")

    async def test_j_does_not_go_past_last(self):
        agents = [
            {"id": 1, "name": "A1", "model": "m1", "ai_backend": "b1", "description": "", "tools": []},
        ]
        app = HomeTestApp(agents)
        async with app.run_test() as pilot:
            await app.push_screen(HomeScreen())
            await pilot.pause()
            await pilot.pause()
            await pilot.press("j")
            await pilot.press("j")
            cards = list(app.screen.query(".agent-card"))
            assert cards[0].has_class("selected")


class TestAgentCard:
    async def test_agent_card_displays_info(self):
        agent = {
            "id": 1,
            "name": "My Agent",
            "model": "gpt-4",
            "ai_backend": "openai",
            "description": "Does stuff",
            "tools": ["bash", "read", "write"],
        }

        class CardApp(App):
            def compose(self):
                yield AgentCard(agent)

        app = CardApp()
        async with app.run_test() as pilot:
            card = app.query_one(AgentCard)
            assert card.agent["name"] == "My Agent"
