"""Root test configuration and shared fixtures."""

import pytest
from unittest.mock import MagicMock


@pytest.fixture
def mock_api_client():
    """Mock APIClient with all methods stubbed."""
    client = MagicMock()
    client.base_url = "http://localhost"
    client.login.return_value = {"token": "test-token", "user": {"name": "Test"}}
    client.list_agents.return_value = [
        {
            "id": 1,
            "name": "Test Agent",
            "model": "gpt-4",
            "ai_backend": "openai",
            "description": "A test agent",
            "tools": ["bash", "read"],
        },
        {
            "id": 2,
            "name": "Code Agent",
            "model": "claude-3",
            "ai_backend": "anthropic",
            "description": "A coding agent",
            "tools": ["bash", "read", "write", "edit"],
        },
    ]
    client.create_conversation.return_value = {
        "data": {"id": 42, "agent_id": 1, "messages": []}
    }
    client.send_message.return_value = {"status": "processing"}
    client.submit_tool_result.return_value = {"status": "ok"}
    client.stop_conversation.return_value = {"status": "cancelled"}
    client._get_headers.return_value = {"Authorization": "Bearer test-token"}
    return client


@pytest.fixture
def sample_agent():
    """A sample agent dict for testing."""
    return {
        "id": 1,
        "name": "Test Agent",
        "model": "gpt-4",
        "ai_backend": "openai",
        "description": "A test agent for unit tests",
        "tools": ["bash", "read"],
    }


@pytest.fixture
def sample_tool_request():
    """A sample tool request dict."""
    return {
        "name": "bash",
        "call_id": "call-123",
        "arguments": {"command": "ls -la"},
    }


@pytest.fixture
def mock_tools():
    """Mock tool registry."""
    bash_tool = MagicMock()
    bash_tool.name = "bash"
    bash_tool.execute.return_value = (True, "file1.txt\nfile2.txt", "")
    bash_tool.get_schema.return_value = {
        "name": "bash",
        "description": "Run bash commands",
        "parameters": {
            "type": "object",
            "properties": {"command": {"type": "string"}},
        },
    }

    read_tool = MagicMock()
    read_tool.name = "read"
    read_tool.execute.return_value = (True, "file content here", "")
    read_tool.get_schema.return_value = {
        "name": "read",
        "description": "Read files",
        "parameters": {
            "type": "object",
            "properties": {"file_path": {"type": "string"}},
        },
    }

    return {"bash": bash_tool, "read": read_tool}
