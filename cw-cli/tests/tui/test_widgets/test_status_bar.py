"""Tests for StatusBar widget."""

import pytest
from textual.app import App, ComposeResult

from chinese_worker.tui.widgets.status_bar import StatusBar


class StatusBarApp(App):
    def __init__(self, agent: dict):
        super().__init__()
        self._agent = agent

    def compose(self) -> ComposeResult:
        yield StatusBar(self._agent, id="status-bar")


class TestStatusBar:
    async def test_initial_render_shows_agent_name(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            rendered = str(bar.render())
            assert "Test Agent" in rendered

    async def test_initial_render_shows_model(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            rendered = str(bar.render())
            assert "gpt-4" in rendered

    async def test_initial_status_connected(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            assert bar.status == "Connected"

    async def test_set_status_updates_text(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            bar.set_status("Thinking...")
            assert bar.status == "Thinking..."

    async def test_error_status_sets_flag(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            bar.set_status("Connection lost", error=True)
            assert bar.is_error is True
            assert bar.status == "Connection lost"

    async def test_green_indicator_when_connected(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            rendered = str(bar.render())
            assert "\u25cf" in rendered

    async def test_reactive_agent_name(self, sample_agent):
        app = StatusBarApp(sample_agent)
        async with app.run_test() as pilot:
            bar = app.query_one(StatusBar)
            bar.agent_name = "New Agent"
            rendered = str(bar.render())
            assert "New Agent" in rendered
