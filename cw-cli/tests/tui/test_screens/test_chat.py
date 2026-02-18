"""Tests for ChatScreen."""

import pytest
from unittest.mock import MagicMock, patch

from textual.app import App
from textual.containers import VerticalScroll
from textual.widgets import Input

from chinese_worker.tui.screens.chat import ChatScreen
from chinese_worker.tui.widgets.status_bar import StatusBar
from chinese_worker.tui.widgets.message import ChatMessage


class ChatTestApp(App):
    """App for testing the ChatScreen."""

    def __init__(self, agent, mock_client=None, mock_tools=None):
        super().__init__()
        self._agent = agent
        self.client = mock_client or MagicMock()
        self.current_agent = agent
        self.current_conversation = None
        self.auto_approve_tools = False
        self._tools = mock_tools or {}
        self._tool_schemas = []
        self._client_type = "cli_linux"


class TestChatScreenCompose:
    async def test_has_status_bar(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            app.screen.query_one("#status-bar", StatusBar)

    async def test_has_message_list(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            app.screen.query_one("#message-list", VerticalScroll)

    async def test_has_chat_input(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            input_widget = app.screen.query_one("#chat-input", Input)
            assert "message" in input_widget.placeholder.lower()

    async def test_chat_input_auto_focused(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            await pilot.pause()
            input_widget = app.screen.query_one("#chat-input", Input)
            assert input_widget.has_focus


class TestChatScreenInput:
    async def test_empty_message_ignored(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            await pilot.pause()
            await pilot.press("enter")
            await pilot.pause()
            messages = list(app.screen.query(ChatMessage))
            assert len(messages) == 0

    async def test_slash_command_dispatched(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            await pilot.pause()
            input_widget = app.screen.query_one("#chat-input", Input)
            input_widget.value = "/help"
            await pilot.press("enter")
            await pilot.pause()
            messages = list(app.screen.query(ChatMessage))
            system_msgs = [m for m in messages if m._role == "system"]
            assert len(system_msgs) >= 1


class TestChatScreenActions:
    async def test_action_clear(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            screen = app.screen
            assert isinstance(screen, ChatScreen)
            await screen.action_clear()
            msg_list = screen.query_one("#message-list", VerticalScroll)
            assert len(list(msg_list.children)) == 0

    async def test_stop_operation(self, sample_agent, mock_api_client, mock_tools):
        app = ChatTestApp(sample_agent, mock_api_client, mock_tools)
        async with app.run_test() as pilot:
            await app.push_screen(ChatScreen(app._agent))
            await pilot.pause()
            screen = app.screen
            assert isinstance(screen, ChatScreen)
            screen.is_processing = True
            screen.conversation_id = 42
            await screen.stop_operation()
            assert screen.is_processing is False
