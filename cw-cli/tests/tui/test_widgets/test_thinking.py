"""Tests for ThinkingBlock widget."""

import pytest
from textual.app import App, ComposeResult
from textual.widgets import Static

from chinese_worker.tui.widgets.thinking import ThinkingBlock


class ThinkingApp(App):
    def __init__(self, content: str = ""):
        super().__init__()
        self._content = content

    def compose(self) -> ComposeResult:
        yield ThinkingBlock(self._content)


class TestThinkingBlock:
    async def test_initial_title_is_thinking(self):
        app = ThinkingApp()
        async with app.run_test() as pilot:
            block = app.query_one(ThinkingBlock)
            assert block.title == "Thinking..."

    async def test_has_thinking_block_class(self):
        app = ThinkingApp()
        async with app.run_test() as pilot:
            block = app.query_one(ThinkingBlock)
            assert block.has_class("thinking-block")

    async def test_starts_collapsed(self):
        app = ThinkingApp()
        async with app.run_test() as pilot:
            block = app.query_one(ThinkingBlock)
            assert block.collapsed is True

    async def test_compose_yields_thinking_content(self):
        app = ThinkingApp("Some reasoning")
        async with app.run_test() as pilot:
            content = app.query_one("#thinking-content", Static)
            assert content.has_class("thinking-text")

    async def test_update_content(self):
        app = ThinkingApp()
        async with app.run_test() as pilot:
            block = app.query_one(ThinkingBlock)
            block.update_content("New thinking text")
            assert block._content == "New thinking text"

    async def test_finalize_updates_title_with_word_count(self):
        app = ThinkingApp("one two three four five")
        async with app.run_test() as pilot:
            block = app.query_one(ThinkingBlock)
            block.finalize()
            assert block.title == "Thinking (5 words)"

    async def test_finalize_with_empty_content(self):
        app = ThinkingApp("")
        async with app.run_test() as pilot:
            block = app.query_one(ThinkingBlock)
            block.finalize()
            assert "Thinking" in block.title
