"""Tests for ToolExecutor."""

import pytest
from unittest.mock import MagicMock

from chinese_worker.tui.handlers.tools import ToolExecutor


@pytest.fixture
def executor_with_messages(mock_tools, mock_api_client):
    messages = []
    executor = ToolExecutor(
        tools=mock_tools,
        client=mock_api_client,
        on_message=lambda msg: messages.append(msg),
    )
    return executor, messages


class TestToolExecutor:
    async def test_execute_known_tool(self, executor_with_messages):
        ex, messages = executor_with_messages
        success, output = await ex.execute(42, {
            "name": "bash",
            "call_id": "c1",
            "arguments": {"command": "ls"},
        })
        assert success is True
        assert output is not None

    async def test_execute_unknown_tool(self, executor_with_messages):
        ex, messages = executor_with_messages
        success, output = await ex.execute(42, {
            "name": "nonexistent",
            "call_id": "c2",
            "arguments": {},
        })
        assert success is False
        assert output is None

    async def test_execute_missing_name(self, executor_with_messages):
        ex, messages = executor_with_messages
        success, output = await ex.execute(42, {
            "call_id": "c3",
            "arguments": {},
        })
        assert success is False
        assert output is None

    async def test_execute_missing_call_id(self, executor_with_messages):
        ex, messages = executor_with_messages
        success, output = await ex.execute(42, {
            "name": "bash",
            "arguments": {},
        })
        assert success is False
        assert output is None

    async def test_reject_submits_refusal(self, executor_with_messages, mock_api_client):
        ex, messages = executor_with_messages
        await ex.reject(42, {"name": "bash", "call_id": "c4", "arguments": {}})
        mock_api_client.submit_tool_result.assert_called_once()

    async def test_execute_tool_failure(self, executor_with_messages, mock_tools):
        ex, messages = executor_with_messages
        mock_tools["bash"].execute.return_value = (False, "", "Permission denied")
        success, output = await ex.execute(42, {
            "name": "bash",
            "call_id": "c5",
            "arguments": {"command": "rm -rf /"},
        })
        assert success is False

    async def test_message_callback_called(self, executor_with_messages):
        ex, messages = executor_with_messages
        await ex.execute(42, {
            "name": "bash",
            "call_id": "c6",
            "arguments": {"command": "ls"},
        })
        assert len(messages) > 0

    async def test_get_tool_names(self, executor_with_messages):
        ex, _ = executor_with_messages
        names = ex.get_tool_names()
        assert "bash" in names
        assert "read" in names
