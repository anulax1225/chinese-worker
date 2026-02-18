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
        # Stages only exist in the API when they are completed
        self.add_class("completed")

    def render(self) -> str:
        stage = self.stage
        # API field is "stage" (e.g. "extracted", "cleaned", "normalized", "chunked")
        name = stage.get("stage", "unknown").replace("_", " ").title()
        char_count = stage.get("character_count")
        word_count = stage.get("word_count")

        icon = "✓"

        # Build details from available metadata
        details_parts = []
        if char_count is not None:
            details_parts.append(f"{char_count:,} chars")
        if word_count is not None:
            details_parts.append(f"{word_count:,} words")
        details = "  ".join(details_parts)

        parts = [f"{icon} {name}"]
        if details:
            parts.append(details)

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

    # Map from document status to human-readable stage name
    ACTIVE_STAGE_NAMES: dict[str, str] = {
        "extracting": "Extracting",
        "cleaning": "Cleaning",
        "normalizing": "Normalizing",
        "chunking": "Chunking",
    }

    def __init__(
        self,
        stages: list[dict[str, Any]] | None = None,
        doc_status: str = "",
        **kwargs,
    ) -> None:
        """Initialize the pipeline widget.

        Args:
            stages: List of stage data from get_document_stages() API
            doc_status: Current document status (for showing active stage)
        """
        super().__init__(**kwargs)
        self.stages = stages or []
        self.doc_status = doc_status

    def compose(self) -> ComposeResult:
        yield Static("── Processing Pipeline ──", classes="pipeline-header")

        has_content = bool(self.stages) or self.doc_status in self.ACTIVE_STAGE_NAMES

        if not has_content:
            yield Static("  [#6c7086]No processing data available[/#6c7086]")
            return

        for stage in self.stages:
            yield PipelineStage(stage)

        # Show current active stage if document is still processing
        if self.doc_status in self.ACTIVE_STAGE_NAMES:
            active_name = self.ACTIVE_STAGE_NAMES[self.doc_status]
            yield Static(
                f"  [yellow]⏳[/yellow] [yellow]{active_name}[/yellow] [#7f849c]in progress...[/#7f849c]",
                classes="pipeline-header",
            )

    def update_stages(self, stages: list[dict[str, Any]]) -> None:
        """Update stages data and refresh display.

        Args:
            stages: New stage data
        """
        self.stages = stages
        self.remove_children()
        for widget in self.compose():
            self.mount(widget)
