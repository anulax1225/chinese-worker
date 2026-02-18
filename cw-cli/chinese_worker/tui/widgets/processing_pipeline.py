"""Processing pipeline widget for document detail view."""

from typing import Any

from textual.app import ComposeResult
from textual.containers import Vertical
from textual.widgets import Static


class PipelineStage(Static):
    """Single stage in the processing pipeline."""

    DEFAULT_CSS = """
    PipelineStage {
        height: 1;
        padding: 0 2;
    }

    PipelineStage.completed {
        color: $success;
    }

    PipelineStage.failed {
        color: $error;
    }

    PipelineStage.active {
        color: $warning;
    }

    PipelineStage.pending {
        color: #6c7086;
    }
    """

    def __init__(self, stage: dict[str, Any], **kwargs) -> None:
        super().__init__(**kwargs)
        self.stage = stage
        status = stage.get("status", "pending")

        # Add status class
        if status == "completed":
            self.add_class("completed")
        elif status == "failed":
            self.add_class("failed")
        elif status == "processing" or status == "active":
            self.add_class("active")
        else:
            self.add_class("pending")

    def render(self) -> str:
        stage = self.stage
        status = stage.get("status", "pending")
        name = stage.get("name", "Unknown")
        duration = stage.get("duration")
        details = stage.get("details", "")
        error = stage.get("error", "")

        # Icon based on status
        if status == "completed":
            icon = "✓"
        elif status == "failed":
            icon = "✗"
        elif status == "processing" or status == "active":
            icon = "⏳"
        else:
            icon = "○"

        # Format duration
        duration_text = ""
        if duration is not None:
            duration_text = f"{duration:.1f}s" if isinstance(duration, float) else f"{duration}s"

        # Build the stage line
        parts = [f"{icon} {name}"]
        if duration_text:
            parts.append(duration_text)
        if details:
            parts.append(details)
        if error:
            parts.append(f"[red]{error}[/red]")

        return "  ".join(parts)


class ProcessingPipeline(Vertical):
    """Visual display of the document processing pipeline.

    Shows the 4-phase processing stages: extraction, cleaning,
    normalization, and chunking with their status and details.
    """

    EXPECTED_STAGES = ["extraction", "cleaning", "normalization", "chunking"]

    DEFAULT_CSS = """
    ProcessingPipeline {
        height: auto;
        padding: 1 0;
    }

    ProcessingPipeline > .pipeline-header {
        text-style: bold;
        color: #cdd6f4;
        padding: 0 2;
        margin-bottom: 1;
    }
    """

    def __init__(self, stages: list[dict[str, Any]] | None = None, **kwargs) -> None:
        """Initialize the pipeline widget.

        Args:
            stages: List of stage data from get_document_stages() API
        """
        super().__init__(**kwargs)
        self.stages = stages or []

    def compose(self) -> ComposeResult:
        yield Static("── Processing Pipeline ──", classes="pipeline-header")

        if not self.stages:
            yield Static("  [#6c7086]No processing data available[/#6c7086]")
            return

        for stage in self.stages:
            yield PipelineStage(stage)

    def update_stages(self, stages: list[dict[str, Any]]) -> None:
        """Update stages data and refresh display.

        Args:
            stages: New stage data
        """
        self.stages = stages
        self.remove_children()
        for widget in self.compose():
            self.mount(widget)
