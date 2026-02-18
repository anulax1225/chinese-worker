"""Tests for ChatMessage widget."""

import pytest
from textual.app import App, ComposeResult
from textual.widgets import Static, Markdown

from chinese_worker.tui.widgets.message import ChatMessage


class MessageApp(App):
    """Test app that mounts a ChatMessage."""

    def __init__(self, content: str, role: str = "user", streaming: bool = False):
        super().__init__()
        self._content = content
        self._role = role
        self._streaming = streaming

    def compose(self) -> ComposeResult:
        yield ChatMessage(self._content, role=self._role, streaming=self._streaming)


class TestChatMessageCompose:
    async def test_user_message_has_static_content(self):
        app = MessageApp("Hello world", role="user")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert msg.has_class("message-user")
            content = msg.query_one("#message-content", Static)
            assert "Hello world" in str(content.render())

    async def test_assistant_message_has_markdown(self):
        app = MessageApp("# Title", role="assistant")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert msg.has_class("message-assistant")
            msg.query_one("#message-prefix", Static)
            msg.query_one("#message-content", Markdown)

    async def test_streaming_assistant_starts_empty(self):
        app = MessageApp("", role="assistant", streaming=True)
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert msg.has_class("streaming")
            md = msg.query_one("#message-content", Markdown)
            assert md is not None

    async def test_error_message_class(self):
        app = MessageApp("Something broke", role="error")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert msg.has_class("message-error")

    async def test_system_message_class(self):
        app = MessageApp("System info", role="system")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert msg.has_class("message-system")

    async def test_tool_message_class(self):
        app = MessageApp("Tool output", role="tool")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert msg.has_class("message-tool")


class TestChatMessageUpdate:
    async def test_update_user_content(self):
        app = MessageApp("Old text", role="user")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            msg.update_content("New text")
            content = msg.query_one("#message-content", Static)
            assert "New text" in str(content.render())

    async def test_set_streaming_adds_class(self):
        app = MessageApp("Content", role="assistant")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            assert not msg.has_class("streaming")
            msg.set_streaming(True)
            assert msg.has_class("streaming")
            msg.set_streaming(False)
            assert not msg.has_class("streaming")

    async def test_get_markdown_widget_for_assistant(self):
        app = MessageApp("Content", role="assistant")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            md = msg.get_markdown_widget()
            assert isinstance(md, Markdown)

    async def test_get_markdown_widget_raises_for_user(self):
        app = MessageApp("Content", role="user")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            with pytest.raises(ValueError):
                msg.get_markdown_widget()

    async def test_user_role_render(self):
        app = MessageApp("Hello", role="user")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            content = msg.query_one("#message-content", Static)
            rendered = str(content.render())
            assert "You:" in rendered
            assert "Hello" in rendered

    async def test_error_role_render(self):
        app = MessageApp("Oops", role="error")
        async with app.run_test() as pilot:
            msg = app.query_one(ChatMessage)
            content = msg.query_one("#message-content", Static)
            rendered = str(content.render())
            assert "Oops" in rendered
