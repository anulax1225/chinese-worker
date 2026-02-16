"""Status bar widget."""

from typing import Dict, Any

from textual.app import ComposeResult
from textual.widgets import Static


class StatusBar(Static):
    """Status bar showing agent and connection info."""

    def __init__(self, agent: Dict[str, Any], **kwargs) -> None:
        super().__init__(**kwargs)
        self.agent = agent
        self._status = "Connected"
        self._is_error = False

    def compose(self) -> ComposeResult:
        """Create status bar content."""
        yield Static(self._render_content(), id="status-content")

    def _render_content(self) -> str:
        """Render status bar content."""
        agent_name = self.agent.get("name", "Unknown")
        model = self.agent.get("model", "")

        status_color = "red" if self._is_error else "green"
        status_text = f"[{status_color}]{self._status}[/{status_color}]"

        return f"[bold]{agent_name}[/bold] [dim]({model})[/dim]  {status_text}"

    def set_status(self, status: str, error: bool = False) -> None:
        """Update the status."""
        self._status = status
        self._is_error = error
        content = self.query_one("#status-content", Static)
        content.update(self._render_content())
