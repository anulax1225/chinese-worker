"""Widget for displaying tool execution status and results."""

import json
from typing import Any

from textual.reactive import reactive
from textual.widgets import Static


# Document tools that get special formatting
DOCUMENT_TOOLS = {"document_search", "document_read", "document_list", "document_chunks"}


class ToolStatusWidget(Static):
    """Shows a tool's lifecycle: executing -> completed/failed."""

    tool_name: reactive[str] = reactive("")
    call_id: reactive[str] = reactive("")
    status: reactive[str] = reactive("executing")
    result_content: reactive[str] = reactive("")
    success: reactive[bool] = reactive(True)

    def __init__(
        self,
        tool_name: str,
        call_id: str = "",
        tool_input: dict[str, Any] | None = None,
        **kwargs,
    ) -> None:
        super().__init__(**kwargs)
        self.tool_name = tool_name
        self.call_id = call_id
        self.tool_input = tool_input or {}
        self.add_class("tool-status")

    def _is_document_tool(self) -> bool:
        """Check if this is a document-related tool."""
        return self.tool_name in DOCUMENT_TOOLS

    def _render_document_tool(self) -> str:
        """Render document tool with special formatting."""
        icon = "📎"

        if self.status == "executing":
            return self._render_document_executing(icon)
        elif self.status == "completed" and self.success:
            return self._render_document_completed(icon)
        elif self.status == "completed" and not self.success:
            return self._render_document_failed(icon)
        return f"[#7f849c]{icon} {self.tool_name}[/#7f849c]"

    def _render_document_executing(self, icon: str) -> str:
        """Render document tool while executing."""
        if self.tool_name == "document_search":
            query = self.tool_input.get("query", "")
            doc_name = self.tool_input.get("document_name", "all documents")
            return f'{icon} [bold]document_search[/bold]: "{query}" in "{doc_name}" [#7f849c]searching...[/#7f849c]'
        elif self.tool_name == "document_read":
            doc_name = self.tool_input.get("document_name", "document")
            return f'{icon} [bold]document_read[/bold]: "{doc_name}" [#7f849c]reading...[/#7f849c]'
        elif self.tool_name == "document_list":
            return f"{icon} [bold]document_list[/bold] [#7f849c]listing...[/#7f849c]"
        return f"{icon} [bold]{self.tool_name}[/bold] [#7f849c]running...[/#7f849c]"

    def _render_document_completed(self, icon: str) -> str:
        """Render document tool after successful completion."""
        result_data = self._parse_result_json()

        if self.tool_name == "document_search":
            query = self.tool_input.get("query", "")
            doc_name = self.tool_input.get("document_name", "all documents")
            header = f'{icon} [bold]document_search[/bold]: "{query}" in "{doc_name}"'

            chunks = result_data.get("chunks", []) if result_data else []
            if chunks:
                similarities = [f"{c.get('similarity', 0):.2f}" for c in chunks[:3]]
                return f"{header}\n  [#a6e3a1]⤷[/#a6e3a1] Found {len(chunks)} chunks (similarity: {', '.join(similarities)})"
            return f"{header}\n  [#a6e3a1]⤷[/#a6e3a1] No matching chunks found"

        elif self.tool_name == "document_read":
            doc_name = self.tool_input.get("document_name", "document")
            content_len = len(self.result_content) if self.result_content else 0
            return f'{icon} [bold]document_read[/bold]: "{doc_name}"\n  [#a6e3a1]⤷[/#a6e3a1] Read {content_len} characters'

        elif self.tool_name == "document_list":
            docs = result_data.get("documents", []) if result_data else []
            return f"{icon} [bold]document_list[/bold]\n  [#a6e3a1]⤷[/#a6e3a1] Found {len(docs)} documents"

        # Fallback
        preview = self._truncate(self.result_content, 150)
        result = f"[#a6e3a1]✓[/#a6e3a1] {icon} [bold]{self.tool_name}[/bold]"
        if preview:
            result += f"\n[#7f849c]{preview}[/#7f849c]"
        return result

    def _render_document_failed(self, icon: str) -> str:
        """Render document tool after failure."""
        preview = self._truncate(self.result_content, 150)
        result = f"[#f38ba8]✗[/#f38ba8] {icon} [bold]{self.tool_name}[/bold] [#f38ba8]failed[/#f38ba8]"
        if preview:
            result += f"\n[#7f849c]{preview}[/#7f849c]"
        return result

    def _parse_result_json(self) -> dict[str, Any] | None:
        """Try to parse result content as JSON."""
        if not self.result_content:
            return None
        try:
            return json.loads(self.result_content)
        except (json.JSONDecodeError, TypeError):
            return None

    def render(self) -> str:
        # Use special rendering for document tools
        if self._is_document_tool():
            return self._render_document_tool()

        # Standard tool rendering
        if self.status == "executing":
            return f"[#fab387]▶[/#fab387] [bold]{self.tool_name}[/bold] [#7f849c]running...[/#7f849c]"
        elif self.status == "completed" and self.success:
            preview = self._truncate(self.result_content, 200)
            result = f"[#a6e3a1]✓[/#a6e3a1] [bold]{self.tool_name}[/bold]"
            if preview:
                result += f"\n[#7f849c]{preview}[/#7f849c]"
            return result
        elif self.status == "completed" and not self.success:
            preview = self._truncate(self.result_content, 200)
            result = f"[#f38ba8]✗[/#f38ba8] [bold]{self.tool_name}[/bold] [#f38ba8]failed[/#f38ba8]"
            if preview:
                result += f"\n[#7f849c]{preview}[/#7f849c]"
            return result
        return f"[#7f849c]{self.tool_name}[/#7f849c]"

    def complete(self, success: bool, content: str = "") -> None:
        self.success = success
        self.result_content = content
        self.status = "completed"
        if success:
            self.add_class("-success-border")
        else:
            self.add_class("-error-border")

    @staticmethod
    def _truncate(text: str, max_len: int) -> str:
        if not text:
            return ""
        # Take first few lines only
        lines = text.strip().splitlines()
        preview = "\n".join(lines[:5])
        if len(lines) > 5:
            preview += f"\n... ({len(lines) - 5} more lines)"
        if len(preview) > max_len:
            preview = preview[:max_len] + "..."
        return preview
