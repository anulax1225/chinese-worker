"""Tests for ToolStatusWidget."""

import pytest
from textual.app import App, ComposeResult

from chinese_worker.tui.widgets.tool_status import ToolStatusWidget


class ToolStatusApp(App):
    def __init__(self, tool_name: str, call_id: str = ""):
        super().__init__()
        self._tool_name = tool_name
        self._call_id = call_id

    def compose(self) -> ComposeResult:
        yield ToolStatusWidget(self._tool_name, call_id=self._call_id)


class TestToolStatusWidget:
    async def test_initial_status_executing(self):
        app = ToolStatusApp("bash", "call-1")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            assert widget.status == "executing"
            assert widget.tool_name == "bash"
            assert widget.call_id == "call-1"

    async def test_has_tool_status_class(self):
        app = ToolStatusApp("bash")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            assert widget.has_class("tool-status")

    async def test_executing_render(self):
        app = ToolStatusApp("bash")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            rendered = str(widget.render())
            assert "bash" in rendered
            assert "running" in rendered

    async def test_complete_success(self):
        app = ToolStatusApp("bash")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            widget.complete(True, "file1.txt\nfile2.txt")
            assert widget.status == "completed"
            assert widget.success is True
            assert widget.has_class("-success-border")
            rendered = str(widget.render())
            assert "bash" in rendered
            assert "\u2713" in rendered

    async def test_complete_failure(self):
        app = ToolStatusApp("bash")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            widget.complete(False, "command not found")
            assert widget.status == "completed"
            assert widget.success is False
            assert widget.has_class("-error-border")
            rendered = str(widget.render())
            assert "failed" in rendered
            assert "\u2717" in rendered

    async def test_complete_success_with_preview(self):
        app = ToolStatusApp("read")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            widget.complete(True, "Line 1\nLine 2\nLine 3")
            rendered = str(widget.render())
            assert "Line 1" in rendered

    async def test_truncate_long_output(self):
        long_text = "\n".join([f"Line {i}" for i in range(20)])
        app = ToolStatusApp("bash")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            widget.complete(True, long_text)
            rendered = str(widget.render())
            assert "more lines" in rendered

    async def test_truncate_static_method(self):
        result = ToolStatusWidget._truncate("", 100)
        assert result == ""

        short = ToolStatusWidget._truncate("hello", 100)
        assert short == "hello"

    async def test_reactive_status_triggers_rerender(self):
        app = ToolStatusApp("bash")
        async with app.run_test() as pilot:
            widget = app.query_one(ToolStatusWidget)
            rendered1 = str(widget.render())
            assert "running" in rendered1
            widget.complete(True, "done")
            rendered2 = str(widget.render())
            assert "running" not in rendered2
