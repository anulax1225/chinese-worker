"""Status bar widget."""

from typing import Dict, Any

from textual.reactive import reactive
from textual.widgets import Static


class StatusBar(Static):
    """Status bar showing agent name, model, and connection status."""

    agent_name: reactive[str] = reactive("")
    model: reactive[str] = reactive("")
    status: reactive[str] = reactive("Connected")
    is_error: reactive[bool] = reactive(False)

    def __init__(self, agent: Dict[str, Any], **kwargs) -> None:
        super().__init__(**kwargs)
        self.agent_name = agent.get("name", "Unknown")
        self.model = agent.get("model", "")

    def render(self) -> str:
        if self.is_error:
            indicator = "[red]\u25cf[/red]"
        elif self.status in ("Thinking...", "Streaming..."):
            indicator = "[yellow]\u25cf[/yellow]"
        else:
            indicator = "[green]\u25cf[/green]"
        return f" {indicator} [bold]{self.agent_name}[/bold] [dim]({self.model})[/dim]  {self.status}"

    def set_status(self, status: str, error: bool = False) -> None:
        self.status = status
        self.is_error = error
