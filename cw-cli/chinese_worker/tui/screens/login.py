"""Login screen."""

import asyncio

from textual.app import ComposeResult
from textual.containers import Container, Vertical
from textual.screen import Screen
from textual.widgets import Button, Input, Label, Static


class LoginScreen(Screen):
    """Login screen with email/password form."""

    BINDINGS = [
        ("escape", "quit", "Quit"),
    ]

    def compose(self) -> ComposeResult:
        yield Container(
            Static("Chinese Worker", id="login-title"),
            Static("AI Agent Execution Platform", id="login-subtitle"),
            Vertical(
                Label("Email:"),
                Input(placeholder="your@email.com", id="email"),
                Label("Password:"),
                Input(placeholder="Password", password=True, id="password"),
                Button("Login", variant="primary", id="login-btn"),
                Static("", id="login-error"),
                id="login-form",
            ),
            id="login-container",
        )

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        if event.button.id == "login-btn":
            await self._do_login()

    async def on_input_submitted(self, event: Input.Submitted) -> None:
        if event.input.id == "password":
            await self._do_login()
        elif event.input.id == "email":
            self.query_one("#password", Input).focus()

    async def _do_login(self) -> None:
        email_input = self.query_one("#email", Input)
        password_input = self.query_one("#password", Input)
        error_msg = self.query_one("#login-error", Static)

        email = email_input.value.strip()
        password = password_input.value

        if not email or not password:
            error_msg.update("[red]Please enter email and password[/red]")
            return

        error_msg.update("[dim]Logging in...[/dim]")
        email_input.disabled = True
        password_input.disabled = True

        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.login,
                email,
                password,
            )
            # Replace login screen with home (no going back to login)
            from .home import HomeScreen
            self.app.switch_screen(HomeScreen())
        except Exception as e:
            error_msg.update(f"[red]Login failed: {e}[/red]")
            email_input.disabled = False
            password_input.disabled = False

    def action_quit(self) -> None:
        self.app.exit()
