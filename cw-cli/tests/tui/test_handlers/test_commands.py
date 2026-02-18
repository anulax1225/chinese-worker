"""Tests for CommandRegistry."""

import pytest
from unittest.mock import MagicMock, AsyncMock

from chinese_worker.tui.handlers.commands import CommandRegistry


@pytest.fixture
def mock_screen():
    screen = MagicMock()
    screen.add_system_message = MagicMock()
    screen.action_back = AsyncMock()
    screen.action_clear = AsyncMock()
    screen.stop_operation = AsyncMock()
    screen.tool_executor = MagicMock()
    screen.tool_executor.get_tool_names.return_value = ["bash", "read", "write"]
    screen.app = MagicMock()
    return screen


class TestCommandRegistry:
    async def test_help_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/help")
        mock_screen.add_system_message.assert_called_once()
        msg = mock_screen.add_system_message.call_args[0][0]
        assert "Available Commands" in msg

    async def test_clear_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/clear")
        mock_screen.action_clear.assert_called_once()
        # Also sends "Chat cleared." message
        mock_screen.add_system_message.assert_called_once_with("Chat cleared.")

    async def test_stop_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/stop")
        mock_screen.stop_operation.assert_called_once()
        mock_screen.add_system_message.assert_called_once_with("Operation stopped.")

    async def test_agents_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/agents")
        mock_screen.action_back.assert_called_once()

    async def test_tools_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/tools")
        mock_screen.add_system_message.assert_called_once()
        msg = mock_screen.add_system_message.call_args[0][0]
        assert "bash" in msg

    async def test_approve_all_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/approve-all")
        assert mock_screen.app.auto_approve_tools is True
        mock_screen.add_system_message.assert_called_once()

    async def test_exit_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/exit")
        mock_screen.app.exit.assert_called_once()

    async def test_quit_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/quit")
        mock_screen.app.exit.assert_called_once()

    async def test_unknown_command(self, mock_screen):
        registry = CommandRegistry(mock_screen)
        await registry.handle("/foobar")
        mock_screen.add_system_message.assert_called_once()
        msg = mock_screen.add_system_message.call_args[0][0]
        assert "Unknown command" in msg
