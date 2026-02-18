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
            indicator = "[#f38ba8]\u25cf[/#f38ba8]"
        elif self.status in ("Thinking...", "Streaming..."):
            indicator = "[#f9e2af]\u25cf[/#f9e2af]"
        else:
            indicator = "[#a6e3a1]\u25cf[/#a6e3a1]"
        return f" {indicator} [bold]{self.agent_name}[/bold] [#7f849c]({self.model})[/#7f849c]  {self.status}"

    def set_status(self, status: str, error: bool = False) -> None:
        self.status = status
        self.is_error = error
