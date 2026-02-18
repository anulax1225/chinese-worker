"""Upload modal for document upload."""

import asyncio
import os
from typing import Any

from textual.app import ComposeResult
from textual.binding import Binding
from textual.containers import Horizontal, Vertical
from textual.screen import ModalScreen
from textual.widgets import Button, Input, RadioButton, RadioSet, Static, TextArea


class UploadModal(ModalScreen[dict[str, Any] | None]):
    """Modal for uploading documents.

    Supports three upload modes: file path, URL, and text paste.
    Returns the uploaded document data on success, or None if cancelled.
    """

    BINDINGS = [
        Binding("escape", "cancel", "Cancel"),
    ]

    DEFAULT_CSS = """
    UploadModal {
        align: center middle;
    }

    UploadModal > Vertical {
        width: 70;
        height: auto;
        max-height: 80%;
        background: $surface;
        border: thick $border;
        padding: 1 2;
    }

    UploadModal .modal-title {
        text-style: bold;
        text-align: center;
        padding: 1 0;
        color: #cdd6f4;
    }

    UploadModal RadioSet {
        height: auto;
        margin: 1 0;
    }

    UploadModal .input-label {
        margin-top: 1;
        color: #a6adc8;
    }

    UploadModal Input {
        margin: 0 0 1 0;
    }

    UploadModal TextArea {
        height: 10;
        margin: 0 0 1 0;
    }

    UploadModal .supported-types {
        color: #6c7086;
        margin: 1 0;
    }

    UploadModal .button-row {
        margin-top: 1;
        align: center middle;
        height: auto;
    }

    UploadModal Button {
        margin: 0 1;
    }

    UploadModal .status-message {
        margin-top: 1;
        text-align: center;
    }

    UploadModal .hidden {
        display: none;
    }
    """

    def __init__(self) -> None:
        super().__init__()
        self._upload_mode = "file"  # file, url, text
        self._uploading = False

    def compose(self) -> ComposeResult:
        yield Vertical(
            Static("Upload Document", classes="modal-title"),
            Static("Source:", classes="input-label"),
            RadioSet(
                RadioButton("File", id="radio-file", value=True),
                RadioButton("URL", id="radio-url"),
                RadioButton("Text", id="radio-text"),
                id="source-select",
            ),
            # File input
            Static("File path:", id="label-path", classes="input-label"),
            Input(
                placeholder="~/documents/example.pdf",
                id="input-path",
            ),
            # URL input
            Static("URL:", id="label-url", classes="input-label hidden"),
            Input(
                placeholder="https://example.com/document.pdf",
                id="input-url",
                classes="hidden",
            ),
            # Text input
            Static("Content:", id="label-text", classes="input-label hidden"),
            TextArea(id="input-text", classes="hidden"),
            # Title (optional)
            Static("Title (optional):", classes="input-label"),
            Input(placeholder="Document title", id="input-title"),
            # Supported types
            Static(
                "Supported: PDF, DOCX, DOC, TXT, MD, HTML",
                classes="supported-types",
            ),
            # Status
            Static("", id="status-message", classes="status-message"),
            # Buttons
            Horizontal(
                Button("Upload", variant="primary", id="btn-upload"),
                Button("Cancel", variant="default", id="btn-cancel"),
                classes="button-row",
            ),
        )

    def on_radio_set_changed(self, event: RadioSet.Changed) -> None:
        """Handle upload mode change."""
        if event.radio_set.id != "source-select":
            return

        # Get selected mode from radio button id
        if event.pressed.id == "radio-file":
            self._upload_mode = "file"
        elif event.pressed.id == "radio-url":
            self._upload_mode = "url"
        elif event.pressed.id == "radio-text":
            self._upload_mode = "text"

        # Show/hide appropriate inputs
        self._update_input_visibility()

    def _update_input_visibility(self) -> None:
        """Update visibility of input fields based on mode."""
        # File inputs
        label_path = self.query_one("#label-path", Static)
        input_path = self.query_one("#input-path", Input)

        # URL inputs
        label_url = self.query_one("#label-url", Static)
        input_url = self.query_one("#input-url", Input)

        # Text inputs
        label_text = self.query_one("#label-text", Static)
        input_text = self.query_one("#input-text", TextArea)

        # Hide all first
        for widget in [
            label_path,
            input_path,
            label_url,
            input_url,
            label_text,
            input_text,
        ]:
            widget.add_class("hidden")

        # Show based on mode
        if self._upload_mode == "file":
            label_path.remove_class("hidden")
            input_path.remove_class("hidden")
        elif self._upload_mode == "url":
            label_url.remove_class("hidden")
            input_url.remove_class("hidden")
        elif self._upload_mode == "text":
            label_text.remove_class("hidden")
            input_text.remove_class("hidden")

    async def on_button_pressed(self, event: Button.Pressed) -> None:
        """Handle button presses."""
        if event.button.id == "btn-cancel":
            self.dismiss(None)
        elif event.button.id == "btn-upload":
            await self._do_upload()

    async def _do_upload(self) -> None:
        """Perform the upload."""
        if self._uploading:
            return

        self._uploading = True
        status = self.query_one("#status-message", Static)
        upload_btn = self.query_one("#btn-upload", Button)
        upload_btn.disabled = True

        title = self.query_one("#input-title", Input).value.strip() or None

        try:
            status.update("[#a6e3a1]Uploading...[/#a6e3a1]")

            loop = asyncio.get_event_loop()
            result: dict[str, Any] | None = None

            if self._upload_mode == "file":
                path = self.query_one("#input-path", Input).value.strip()
                if not path:
                    status.update("[#f38ba8]Please enter a file path[/#f38ba8]")
                    return

                # Expand user path
                path = os.path.expanduser(path)

                if not os.path.exists(path):
                    status.update(f"[#f38ba8]File not found: {path}[/#f38ba8]")
                    return

                result = await loop.run_in_executor(
                    None,
                    lambda: self.app.client.upload_document(path, title),
                )

            elif self._upload_mode == "url":
                url = self.query_one("#input-url", Input).value.strip()
                if not url:
                    status.update("[#f38ba8]Please enter a URL[/#f38ba8]")
                    return

                result = await loop.run_in_executor(
                    None,
                    lambda: self.app.client.upload_document_from_url(url, title),
                )

            elif self._upload_mode == "text":
                text = self.query_one("#input-text", TextArea).text.strip()
                if not text:
                    status.update("[#f38ba8]Please enter some text[/#f38ba8]")
                    return

                result = await loop.run_in_executor(
                    None,
                    lambda: self.app.client.upload_document_from_text(text, title),
                )

            if result:
                doc_data = result.get("data", result)
                self.dismiss(doc_data)
            else:
                status.update("[#f38ba8]Upload failed[/#f38ba8]")

        except Exception as e:
            status.update(f"[#f38ba8]Error: {e}[/#f38ba8]")
        finally:
            self._uploading = False
            upload_btn.disabled = False

    def action_cancel(self) -> None:
        """Cancel and close the modal."""
        self.dismiss(None)
