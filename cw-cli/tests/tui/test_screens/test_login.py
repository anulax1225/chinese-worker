"""Tests for LoginScreen."""

import pytest
from unittest.mock import MagicMock, patch

from textual.app import App, ComposeResult
from textual.widgets import Input, Button, Static

from chinese_worker.tui.screens.login import LoginScreen


class LoginTestApp(App):
    """App for testing the LoginScreen."""

    def __init__(self, mock_client=None):
        super().__init__()
        self.client = mock_client or MagicMock()


class TestLoginScreenCompose:
    async def test_login_form_elements_exist(self):
        app = LoginTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(LoginScreen())
            screen = app.screen
            screen.query_one("#login-title", Static)
            screen.query_one("#login-subtitle", Static)
            screen.query_one("#email", Input)
            screen.query_one("#password", Input)
            screen.query_one("#login-btn", Button)
            screen.query_one("#login-error", Static)

    async def test_email_input_has_placeholder(self):
        app = LoginTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(LoginScreen())
            email = app.screen.query_one("#email", Input)
            assert email.placeholder == "your@email.com"

    async def test_password_input_is_password(self):
        app = LoginTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(LoginScreen())
            pw = app.screen.query_one("#password", Input)
            assert pw.password is True


class TestLoginScreenSubmission:
    async def test_empty_fields_show_error(self):
        app = LoginTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(LoginScreen())
            await pilot.click("#login-btn")
            await pilot.pause()
            error = app.screen.query_one("#login-error", Static)
            rendered = str(error.render())
            assert "email and password" in rendered.lower() or "Please enter" in rendered

    async def test_email_submit_focuses_password(self):
        app = LoginTestApp()
        async with app.run_test() as pilot:
            await app.push_screen(LoginScreen())
            email_input = app.screen.query_one("#email", Input)
            email_input.focus()
            email_input.value = "test@example.com"
            await pilot.press("enter")
            await pilot.pause()
            pw = app.screen.query_one("#password", Input)
            assert pw.has_focus

    async def test_failed_login_shows_error(self):
        mock_client = MagicMock()
        mock_client.login.side_effect = Exception("Invalid credentials")
        app = LoginTestApp(mock_client)

        async with app.run_test() as pilot:
            await app.push_screen(LoginScreen())
            app.screen.query_one("#email", Input).value = "bad@example.com"
            app.screen.query_one("#password", Input).value = "wrongpass"
            await pilot.click("#login-btn")
            await pilot.pause()
            await pilot.pause()
            await pilot.pause()
            error = app.screen.query_one("#login-error", Static)
            rendered = str(error.render())
            assert "failed" in rendered.lower() or "Invalid" in rendered
