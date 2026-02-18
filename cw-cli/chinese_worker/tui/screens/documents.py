"""Document list screen for browsing and managing documents."""

import asyncio
from typing import Any

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Container, Horizontal, VerticalScroll
from textual.message import Message
from textual.screen import Screen
from textual.widgets import Button, Input, Select, Static

from ..widgets.document_item import DocumentItem


class DocumentStatusUpdate(Message):
    """Posted when a document's status has been updated via polling."""

    def __init__(self, doc_id: int, document: dict[str, Any]) -> None:
        self.doc_id = doc_id
        self.document = document
        super().__init__()


class DocumentListScreen(Screen):
    """Screen for browsing and managing documents.

    Displays a list of all documents with status filtering and search.
    Users can upload, delete, reprocess, or view document details.
    """

    BINDINGS = [
        Binding("escape", "back", "Back"),
        Binding("u", "upload", "Upload"),
        Binding("d", "delete", "Delete"),
        Binding("r", "reprocess", "Reprocess"),
        Binding("f", "filter", "Filter"),
        Binding("/", "search", "Search"),
        Binding("ctrl+r", "refresh", "Refresh"),
    ]

    PROCESSING_STATUSES = ["pending", "extracting", "cleaning", "normalizing", "chunking"]

    def __init__(self) -> None:
        super().__init__()
        self._documents: list[dict[str, Any]] = []
        self._selected_idx = 0
        self._status_filter: str | None = None
        self._search_query = ""
        self._polling_task: asyncio.Task | None = None

    def compose(self) -> ComposeResult:
        yield Container(
            Horizontal(
                Static("[bold]Documents[/bold]", id="doc-title"),
                Select(
                    [
                        ("All", ""),
                        ("Ready", "ready"),
                        ("Processing", "processing"),
                        ("Failed", "failed"),
                    ],
                    value="",
                    id="status-filter",
                    allow_blank=False,
                ),
                Button("U Upload", variant="primary", id="btn-upload"),
                Button("✕", variant="default", id="btn-close"),
                id="doc-header",
            ),
            Input(placeholder="Search documents...", id="search-input"),
            Static("[#7f849c]Loading...[/#7f849c]", id="loading"),
            VerticalScroll(id="doc-list"),
            Static(
                "[dim][bold]Enter[/bold] view  [bold]U[/bold] upload  "
                "[bold]D[/bold] delete  [bold]R[/bold] reprocess  "
                "[bold]/[/bold] search  [bold]Esc[/bold] back[/dim]",
                id="doc-help",
            ),
            id="doc-container",
        )

    async def on_mount(self) -> None:
        asyncio.create_task(self._load_documents())

    async def on_unmount(self) -> None:
        """Cancel polling when screen is unmounted."""
        if self._polling_task and not self._polling_task.done():
            self._polling_task.cancel()

    async def _load_documents(self) -> None:
        """Fetch documents from API and display them."""
        loading = self.query_one("#loading", Static)
        doc_list = self.query_one("#doc-list", VerticalScroll)

        loading.display = True
        doc_list.remove_children()

        try:
            loop = asyncio.get_event_loop()

            # Build status filter for API
            api_status = None
            if self._status_filter == "processing":
                # Don't filter - we'll filter client-side for processing
                api_status = None
            elif self._status_filter:
                api_status = self._status_filter

            self._documents = await loop.run_in_executor(
                None,
                lambda: self.app.client.list_documents(
                    status=api_status,
                    search=self._search_query if self._search_query else None,
                ),
            )

            loading.display = False

            # Client-side filter for "processing" which covers multiple statuses
            filtered = self._filter_documents()

            if not filtered:
                doc_list.mount(
                    Static("[#7f849c]No documents found.[/#7f849c]", id="empty-msg")
                )
                return

            for i, doc in enumerate(filtered):
                item = DocumentItem(doc)
                item.id = f"doc-item-{doc['id']}"
                if i == 0:
                    item.mark_selected(True)
                doc_list.mount(item)

            self._selected_idx = 0

            # Start polling for processing documents
            self._start_polling()

        except Exception as e:
            loading.update(f"[#f38ba8]Error: {e}[/#f38ba8]")

    def _filter_documents(self) -> list[dict[str, Any]]:
        """Filter documents by status (client-side for 'processing').

        Returns:
            Filtered list of documents
        """
        if not self._status_filter:
            return self._documents

        if self._status_filter == "processing":
            return [d for d in self._documents if d.get("status") in self.PROCESSING_STATUSES]

        return self._documents

    def _start_polling(self) -> None:
        """Start polling for documents that are being processed."""
        # Cancel any existing polling task
        if self._polling_task and not self._polling_task.done():
            self._polling_task.cancel()

        # Find documents that need polling
        processing_ids = [
            d["id"]
            for d in self._documents
            if d.get("status") in self.PROCESSING_STATUSES
        ]

        if processing_ids:
            self._polling_task = asyncio.create_task(
                self._poll_document_status(processing_ids)
            )

    async def _poll_document_status(self, doc_ids: list[int]) -> None:
        """Poll status for documents being processed.

        Args:
            doc_ids: List of document IDs to poll
        """
        remaining = set(doc_ids)

        while remaining:
            await asyncio.sleep(2)

            for doc_id in list(remaining):
                try:
                    loop = asyncio.get_event_loop()
                    doc = await loop.run_in_executor(
                        None,
                        self.app.client.get_document,
                        doc_id,
                    )
                    doc_data = doc.get("data", doc)

                    self.post_message(DocumentStatusUpdate(doc_id, doc_data))

                    status = doc_data.get("status")
                    if status in ("ready", "failed"):
                        remaining.discard(doc_id)

                except asyncio.CancelledError:
                    return
                except Exception:
                    pass  # Silently ignore polling errors

    def on_document_status_update(self, event: DocumentStatusUpdate) -> None:
        """Handle document status updates from polling."""
        try:
            item = self.query_one(f"#doc-item-{event.doc_id}", DocumentItem)
            item.update_document(event.document)

            # Update internal list
            for i, doc in enumerate(self._documents):
                if doc["id"] == event.doc_id:
                    self._documents[i] = event.document
                    break
        except Exception:
            pass  # Item might not exist anymore

    async def on_key(self, event) -> None:
        """Handle keyboard navigation."""
        focused = self.app.focused
        if isinstance(focused, Input):
            return

        items = list(self.query(".document-item"))
        if not items:
            return

        if event.key in ("down", "j"):
            self._select_item(min(self._selected_idx + 1, len(items) - 1))
            event.stop()
        elif event.key in ("up", "k"):
            self._select_item(max(self._selected_idx - 1, 0))
            event.stop()
        elif event.key == "enter":
            await self._view_selected()
            event.stop()

    def _select_item(self, idx: int) -> None:
        """Update visual selection to the given index."""
        items = list(self.query(".document-item"))
        for i, item in enumerate(items):
            if isinstance(item, DocumentItem):
                item.mark_selected(i == idx)
        self._selected_idx = idx

        # Scroll selected item into view
        if 0 <= idx < len(items):
            items[idx].scroll_visible()

    async def _view_selected(self) -> None:
        """View the currently selected document."""
        items = list(self.query(".document-item"))
        if 0 <= self._selected_idx < len(items):
            item = items[self._selected_idx]
            if isinstance(item, DocumentItem):
                await self._open_document_detail(item.document)

    async def _open_document_detail(self, document: dict[str, Any]) -> None:
        """Open the document detail screen.

        Args:
            document: Document data dict
        """
        from .document_detail import DocumentDetailScreen

        self.app.push_screen(DocumentDetailScreen(document["id"]))

    async def on_document_item_selected(self, event: DocumentItem.Selected) -> None:
        """Handle document item click."""
        await self._open_document_detail(event.document)

    async def on_select_changed(self, event: Select.Changed) -> None:
        """Handle status filter change."""
        if event.select.id == "status-filter":
            self._status_filter = event.value if event.value else None
            await self._load_documents()

    async def on_input_submitted(self, event: Input.Submitted) -> None:
        """Handle search submission."""
        if event.input.id == "search-input":
            self._search_query = event.input.value.strip()
            await self._load_documents()

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle button presses."""
        if event.button.id == "btn-close":
            self.app.pop_screen()
        elif event.button.id == "btn-upload":
            await self.action_upload()

    async def action_back(self) -> None:
        """Go back to previous screen."""
        self.app.pop_screen()

    async def action_upload(self) -> None:
        """Open the upload modal."""
        from .upload_modal import UploadModal

        async def handle_upload_result(result: dict | None) -> None:
            if result:
                # Navigate to detail screen for the new document
                self.app.push_screen(
                    __import__(
                        "chinese_worker.tui.screens.document_detail",
                        fromlist=["DocumentDetailScreen"],
                    ).DocumentDetailScreen(result["id"])
                )

        self.app.push_screen(UploadModal(), handle_upload_result)

    async def action_delete(self) -> None:
        """Delete the currently selected document."""
        items = list(self.query(".document-item"))
        if not (0 <= self._selected_idx < len(items)):
            return

        item = items[self._selected_idx]
        if not isinstance(item, DocumentItem):
            return

        doc_id = item.document.get("id")
        if not doc_id:
            return

        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.delete_document,
                doc_id,
            )
            self.notify(f"Deleted document #{doc_id}")
            await self._load_documents()
        except Exception as e:
            self.notify(f"Error: {e}", severity="error")

    async def action_reprocess(self) -> None:
        """Reprocess the currently selected document."""
        items = list(self.query(".document-item"))
        if not (0 <= self._selected_idx < len(items)):
            return

        item = items[self._selected_idx]
        if not isinstance(item, DocumentItem):
            return

        doc = item.document
        doc_id = doc.get("id")
        status = doc.get("status")

        if not doc_id:
            return

        if status != "failed":
            self.notify("Only failed documents can be reprocessed", severity="warning")
            return

        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.app.client.reprocess_document,
                doc_id,
            )
            self.notify(f"Reprocessing document #{doc_id}")
            await self._load_documents()
        except Exception as e:
            self.notify(f"Error: {e}", severity="error")

    async def action_filter(self) -> None:
        """Focus the status filter."""
        self.query_one("#status-filter", Select).focus()

    async def action_search(self) -> None:
        """Focus the search input."""
        self.query_one("#search-input", Input).focus()

    async def action_refresh(self) -> None:
        """Refresh the document list."""
        await self._load_documents()
