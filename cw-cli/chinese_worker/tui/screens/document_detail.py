"""Document detail screen showing metadata, stages, and preview."""

import asyncio
from typing import Any

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Container, VerticalScroll
from textual.screen import Screen
from textual.widgets import Button, Markdown, Static

from ..widgets.processing_pipeline import ProcessingPipeline
from ..widgets.document_item import _format_size


class DocumentDetailScreen(Screen):
    """Screen for viewing document details.

    Shows document metadata, processing pipeline stages, and content preview.
    Users can reprocess failed documents or delete documents from here.
    """

    BINDINGS = [
        Binding("escape", "back", "Back"),
        Binding("r", "reprocess", "Reprocess"),
        Binding("d", "delete", "Delete"),
    ]

    def __init__(self, document_id: int) -> None:
        super().__init__()
        self.document_id = document_id
        self._document: dict[str, Any] | None = None
        self._stages: list[dict[str, Any]] = []
        self._preview: str = ""

    def compose(self) -> ComposeResult:
        yield Container(
            Container(
                Button("← Back", variant="default", id="btn-back"),
                Static("[bold]Document[/bold]", id="doc-detail-title"),
                id="detail-header",
            ),
            Static("[#7f849c]Loading...[/#7f849c]", id="loading"),
            VerticalScroll(
                Static("", id="metadata-section"),
                Container(id="pipeline-container"),
                Static("── Preview ──", id="preview-header", classes="section-header"),
                VerticalScroll(Markdown("", id="preview-content"), id="preview-scroll"),
                id="detail-content",
            ),
            Static(
                "[dim][bold]R[/bold] reprocess  [bold]D[/bold] delete  "
                "[bold]Esc[/bold] back[/dim]",
                id="detail-help",
            ),
            id="detail-container",
        )

    async def on_mount(self) -> None:
        asyncio.create_task(self._load_document())

    async def _load_document(self) -> None:
        """Fetch document, stages, and preview from API."""
        loading = self.query_one("#loading", Static)
        content = self.query_one("#detail-content", VerticalScroll)
        title = self.query_one("#doc-detail-title", Static)

        loading.display = True
        content.display = False

        try:
            loop = asyncio.get_event_loop()

            # Fetch document, stages, and preview in parallel
            doc_task = loop.run_in_executor(
                None, self.app.client.get_document, self.document_id
            )
            stages_task = loop.run_in_executor(
                None, self.app.client.get_document_stages, self.document_id
            )

            doc_response, self._stages = await asyncio.gather(doc_task, stages_task)

            self._document = doc_response.get("data", doc_response)

            # Try to get preview (may fail for some document states)
            try:
                preview_response = await loop.run_in_executor(
                    None, self.app.client.get_document_preview, self.document_id
                )
                self._preview = preview_response.get("cleaned_preview") or preview_response.get(
                    "original_preview", ""
                )
            except Exception:
                self._preview = "[Preview not available]"

            loading.display = False
            content.display = True

            # Update title
            doc_title = self._document.get("title") or self._document.get(
                "original_filename", f"Document #{self.document_id}"
            )
            title.update(f"[bold]{doc_title}[/bold]")

            # Update metadata
            self._update_metadata()

            # Update pipeline
            self._update_pipeline()

            # Update preview
            preview_content = self.query_one("#preview-content", Markdown)
            # Truncate preview if too long
            preview_text = self._preview[:5000] if self._preview else "[No content]"
            if len(self._preview) > 5000:
                preview_text += "\n\n[...truncated...]"
            preview_content.update(preview_text)

        except Exception as e:
            loading.update(f"[#f38ba8]Error: {e}[/#f38ba8]")

    def _update_metadata(self) -> None:
        """Update the metadata section."""
        if not self._document:
            return

        doc = self._document
        status = doc.get("status", "unknown")
        mime_type = doc.get("mime_type", "unknown")
        file_size = _format_size(doc.get("file_size"))
        chunk_count = doc.get("chunk_count", 0)
        created_at = doc.get("created_at", "unknown")

        # Format status with color
        status_colors = {
            "ready": "green",
            "failed": "red",
            "pending": "yellow",
            "extracting": "yellow",
            "cleaning": "yellow",
            "normalizing": "yellow",
            "chunking": "yellow",
        }
        status_color = status_colors.get(status, "#7f849c")
        status_icons = {
            "ready": "✓",
            "failed": "✗",
            "pending": "⏳",
            "extracting": "⏳",
            "cleaning": "⏳",
            "normalizing": "⏳",
            "chunking": "⏳",
        }
        status_icon = status_icons.get(status, "?")

        # Format created date
        if created_at and created_at != "unknown":
            try:
                from datetime import datetime

                dt = datetime.fromisoformat(created_at.replace("Z", "+00:00"))
                created_display = dt.strftime("%Y-%m-%d %H:%M")
            except (ValueError, TypeError):
                created_display = created_at
        else:
            created_display = "unknown"

        metadata_text = (
            f"[bold]Status:[/bold] [{status_color}]{status_icon} {status}[/{status_color}]\n"
            f"[bold]Format:[/bold] {mime_type}  [bold]Size:[/bold] {file_size}\n"
            f"[bold]Chunks:[/bold] {chunk_count}  [bold]Created:[/bold] {created_display}"
        )

        metadata = self.query_one("#metadata-section", Static)
        metadata.update(metadata_text)

    def _update_pipeline(self) -> None:
        """Update the processing pipeline display."""
        container = self.query_one("#pipeline-container", Container)
        container.remove_children()

        if self._stages:
            doc_status = self._document.get("status", "") if self._document else ""
            pipeline = ProcessingPipeline(self._stages, doc_status=doc_status)
            container.mount(pipeline)
        else:
            container.mount(
                Static("[#6c7086]No processing data available[/#6c7086]")
            )

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle button presses."""
        if event.button.id == "btn-back":
            self.app.pop_screen()

    async def action_back(self) -> None:
        """Go back to previous screen."""
        self.app.pop_screen()

    async def action_reprocess(self) -> None:
        """Reprocess the document."""
        if not self._document:
            return

        status = self._document.get("status")
        if status != "failed":
            self.notify("Only failed documents can be reprocessed", severity="warning")
            return

        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.reprocess_document,
                self.document_id,
            )
            self.notify(f"Reprocessing document #{self.document_id}")
            await self._load_document()
        except Exception as e:
            self.notify(f"Error: {e}", severity="error")

    async def action_delete(self) -> None:
        """Delete the document."""
        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.delete_document,
                self.document_id,
            )
            self.notify(f"Deleted document #{self.document_id}")
            self.app.pop_screen()
        except Exception as e:
            self.notify(f"Error: {e}", severity="error")
