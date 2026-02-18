"""Tests for ToolApprovalPanel widget."""

import pytest
from textual.app import App, ComposeResult
from textual.widgets import Button, Static

from chinese_worker.tui.widgets.tool_panel import ToolApprovalPanel


class ToolPanelApp(App):
    def __init__(self, tool_request: dict):
        super().__init__()
        self._tool_request = tool_request
        self.last_message = None

    def compose(self) -> ComposeResult:
        yield ToolApprovalPanel(self._tool_request)

    def on_tool_approval_panel_approved(self, event: ToolApprovalPanel.Approved) -> None:
        self.last_message = ("approved", event.tool_request)

    def on_tool_approval_panel_rejected(self, event: ToolApprovalPanel.Rejected) -> None:
        self.last_message = ("rejected", event.tool_request)

    def on_tool_approval_panel_approve_all(self, event: ToolApprovalPanel.ApproveAll) -> None:
        self.last_message = ("approve_all", event.tool_request)


class TestToolApprovalPanelCompose:
    async def test_has_tool_panel_class(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            panel = app.query_one(ToolApprovalPanel)
            assert panel.has_class("tool-panel")

    async def test_shows_tool_name(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            header = app.query_one("#tool-header", Static)
            assert "bash" in str(header.render())

    async def test_shows_args(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            args = app.query_one("#tool-args", Static)
            assert "ls -la" in str(args.render())

    async def test_has_three_buttons(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            buttons = app.query(Button)
            assert len(buttons) == 3

    async def test_has_help_text(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            help_text = app.query_one("#tool-help", Static)
            assert help_text is not None


class TestToolApprovalPanelInteraction:
    async def test_yes_button_posts_approved(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            await pilot.click("#btn-yes")
            await pilot.pause()
            assert app.last_message is not None
            assert app.last_message[0] == "approved"
            assert app.last_message[1]["call_id"] == "call-123"

    async def test_no_button_posts_rejected(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            await pilot.click("#btn-no")
            await pilot.pause()
            assert app.last_message is not None
            assert app.last_message[0] == "rejected"

    async def test_all_button_posts_approve_all(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            await pilot.click("#btn-all")
            await pilot.pause()
            assert app.last_message is not None
            assert app.last_message[0] == "approve_all"

    async def test_y_key_binding(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            panel = app.query_one(ToolApprovalPanel)
            panel.focus()
            await pilot.press("y")
            await pilot.pause()
            assert app.last_message is not None
            assert app.last_message[0] == "approved"

    async def test_n_key_binding(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            panel = app.query_one(ToolApprovalPanel)
            panel.focus()
            await pilot.press("n")
            await pilot.pause()
            assert app.last_message is not None
            assert app.last_message[0] == "rejected"

    async def test_a_key_binding(self, sample_tool_request):
        app = ToolPanelApp(sample_tool_request)
        async with app.run_test() as pilot:
            panel = app.query_one(ToolApprovalPanel)
            panel.focus()
            await pilot.press("a")
            await pilot.pause()
            assert app.last_message is not None
            assert app.last_message[0] == "approve_all"


class TestToolApprovalPanelFormatArgs:
    async def test_bash_format(self):
        req = {"name": "bash", "call_id": "c1", "arguments": {"command": "echo hi"}}
        app = ToolPanelApp(req)
        async with app.run_test() as pilot:
            args = app.query_one("#tool-args", Static)
            assert "echo hi" in str(args.render())

    async def test_read_format(self):
        req = {"name": "read", "call_id": "c2", "arguments": {"file_path": "/tmp/x.py"}}
        app = ToolPanelApp(req)
        async with app.run_test() as pilot:
            args = app.query_one("#tool-args", Static)
            assert "/tmp/x.py" in str(args.render())

    async def test_unknown_tool_format(self):
        req = {"name": "custom", "call_id": "c3", "arguments": {"foo": "bar", "baz": "qux"}}
        app = ToolPanelApp(req)
        async with app.run_test() as pilot:
            args = app.query_one("#tool-args", Static)
            rendered = str(args.render())
            assert "foo" in rendered
            assert "bar" in rendered
