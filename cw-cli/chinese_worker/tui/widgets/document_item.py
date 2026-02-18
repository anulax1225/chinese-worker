"""Document item widget for lists."""

from typing import Any

from textual.app import ComposeResult
from textual.binding import Binding
from textual.message import Message
from textual.widgets import Static

from ..utils.time import relative_time


def _format_size(size_bytes: int | None) -> str:
    """Format file size in human-readable format."""
    if not size_bytes:
        return "? KB"

    if size_bytes < 1024:
        return f"{size_bytes} B"
    elif size_bytes < 1024 * 1024:
        return f"{size_bytes / 1024:.1f} KB"
    elif size_bytes < 1024 * 1024 * 1024:
        return f"{size_bytes / (1024 * 1024):.1f} MB"
    else:
        return f"{size_bytes / (1024 * 1024 * 1024):.1f} GB"


class DocumentItem(Static):
    """Single document row with metadata and status.

    Displays document information including title, format, size, chunk count,
    processing status, and relative timestamp. Posts a Selected message when
    clicked or selected via keyboard.
    """

    BINDINGS = [
        Binding("enter", "select", "Select", show=False),
    ]

    FORMAT_ICONS: dict[str, str] = {
        "application/pdf": "📄",
        "text/markdown": "📝",
        "text/plain": "📃",
        "text/html": "🌐",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document": "📘",
        "application/msword": "📘",
        "image/png": "🖼️",
        "image/jpeg": "🖼️",
    }

    STATUS_DISPLAY: dict[str, tuple[str, str]] = {
        "ready": ("[green]✓[/green]", "ready"),
        "failed": ("[red]✗[/red]", "failed"),
        "pending": ("[yellow]⏳[/yellow]", "pending"),
        "extracting": ("[yellow]████░░░░[/yellow]", "extracting..."),
        "cleaning": ("[yellow]█████░░░[/yellow]", "cleaning..."),
        "normalizing": ("[yellow]██████░░[/yellow]", "normalizing..."),
        "chunking": ("[yellow]███████░[/yellow]", "chunking..."),
    }

    PROCESSING_STAGES = ["extracting", "cleaning", "normalizing", "chunking"]

    DEFAULT_CSS = """
    DocumentItem {
        height: auto;
        padding: 1 2;
        border: solid $surface;
        border-left: thick transparent;
    }

    DocumentItem:hover {
        background: $surface;
    }

    DocumentItem:focus {
        border: solid $primary;
    }

    DocumentItem.selected {
        background: $surface;
        border: solid $primary;
    }

    DocumentItem.ready {
        border-left: thick $success;
    }

    DocumentItem.failed {
        border-left: thick $error;
    }

    DocumentItem.processing {
        border-left: thick $warning;
    }

    #doc-header {
        text-style: bold;
    }

    #doc-meta {
        color: #7f849c;
    }

    #doc-status {
        margin-top: 0;
    }
    """

    class Selected(Message):
        """Posted when document is selected."""

        def __init__(self, document: dict[str, Any]) -> None:
            self.document = document
            super().__init__()

    def __init__(
        self,
        document: dict[str, Any],
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self.document = document
        self.can_focus = True
        self.add_class("document-item")

        # Add status-based class
        status = document.get("status", "pending")
        if status == "ready":
            self.add_class("ready")
        elif status == "failed":
            self.add_class("failed")
        elif status in self.PROCESSING_STAGES or status == "pending":
            self.add_class("processing")

    def compose(self) -> ComposeResult:
        doc = self.document
        title = doc.get("title") or doc.get("original_filename", "Untitled")
        mime_type = doc.get("mime_type", "")
        size = _format_size(doc.get("file_size"))
        chunk_count = doc.get("chunk_count", 0)
        status = doc.get("status", "pending")
        created_at = relative_time(doc.get("created_at"))

        # Get format icon
        icon = self.FORMAT_ICONS.get(mime_type, "📄")

        # Get short format name from mime type
        format_name = mime_type.split("/")[-1].upper() if mime_type else "?"
        if format_name == "VND.OPENXMLFORMATS-OFFICEDOCUMENT.WORDPROCESSINGML.DOCUMENT":
            format_name = "DOCX"
        elif format_name == "PLAIN":
            format_name = "TXT"
        elif format_name == "MARKDOWN":
            format_name = "MD"

        # Get status display
        status_icon, status_text = self.STATUS_DISPLAY.get(
            status, ("[#7f849c]?[/#7f849c]", status)
        )

        # Build chunks display (only show if ready)
        chunks_text = f"{chunk_count} chunks" if status == "ready" else ""

        yield Static(
            f"{icon} {title}",
            id="doc-header",
        )
        yield Static(
            f"   {format_name} · {size}"
            + (f" · {chunks_text}" if chunks_text else "")
            + f" · {status_icon} {status_text}"
            + f"   {created_at}",
            id="doc-meta",
        )

    async def on_click(self) -> None:
        """Handle click events."""
        self.post_message(self.Selected(self.document))

    def action_select(self) -> None:
        """Handle enter key selection."""
        self.post_message(self.Selected(self.document))

    def mark_selected(self, is_selected: bool = True) -> None:
        """Mark this item as visually selected in the list.

        Args:
            is_selected: Whether this item is selected
        """
        if is_selected:
            self.add_class("selected")
        else:
            self.remove_class("selected")

    def update_document(self, document: dict[str, Any]) -> None:
        """Update document data and refresh display.

        Args:
            document: Updated document data
        """
        self.document = document

        # Update status classes
        self.remove_class("ready", "failed", "processing")
        status = document.get("status", "pending")
        if status == "ready":
            self.add_class("ready")
        elif status == "failed":
            self.add_class("failed")
        elif status in self.PROCESSING_STAGES or status == "pending":
            self.add_class("processing")

        # Re-render by clearing and composing
        self.remove_children()
        for widget in self.compose():
            self.mount(widget)
