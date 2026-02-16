"""Welcome/Login screen."""

import asyncio

from textual.app import ComposeResult
from textual.containers import Container, Vertical
from textual.screen import Screen
from textual.widgets import Button, Input, Label, Static


class WelcomeScreen(Screen):
    """Welcome screen with login form."""

    BINDINGS = [
        ("escape", "quit", "Quit"),
    ]

    def compose(self) -> ComposeResult:
        """Create child widgets."""
        yield Container(
            Static("Chinese Worker", id="title"),
            Static("AI Agent Execution Platform", id="subtitle"),
            Vertical(
                Label("Email:"),
                Input(placeholder="your@email.com", id="email"),
                Label("Password:"),
                Input(placeholder="Password", password=True, id="password"),
                Button("Login", variant="primary", id="login-btn"),
                Static("", id="error-msg"),
                id="login-form",
            ),
            id="welcome-container",
        )

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle button press."""
        if event.button.id == "login-btn":
            await self.do_login()

    async def on_input_submitted(self, event: Input.Submitted) -> None:
        """Handle enter key in input fields."""
        if event.input.id == "password":
            await self.do_login()
        elif event.input.id == "email":
            # Focus password field
            self.query_one("#password", Input).focus()

    async def do_login(self) -> None:
        """Perform login."""
        email_input = self.query_one("#email", Input)
        password_input = self.query_one("#password", Input)
        error_msg = self.query_one("#error-msg", Static)

        email = email_input.value.strip()
        password = password_input.value

        if not email or not password:
            error_msg.update("[red]Please enter email and password[/red]")
            return

        # Clear error and disable inputs
        error_msg.update("[dim]Logging in...[/dim]")
        email_input.disabled = True
        password_input.disabled = True

        try:
            # Run blocking API call in executor
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.login,
                email,
                password,
            )
            await self.app.on_login_success()
        except Exception as e:
            error_msg.update(f"[red]Login failed: {str(e)}[/red]")
            email_input.disabled = False
            password_input.disabled = False

    def action_quit(self) -> None:
        """Quit the app."""
        self.app.exit()
