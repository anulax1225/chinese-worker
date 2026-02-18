"""Main chat screen."""

import asyncio
from typing import Optional, Dict, Any, List

from textual.app import ComposeResult
from textual.containers import VerticalScroll
from textual.screen import Screen
from textual.widgets import Input, Markdown
from textual.binding import Binding

from ..widgets.message import ChatMessage
from ..widgets.status_bar import StatusBar
from ..widgets.thinking import ThinkingBlock
from ..widgets.tool_panel import ToolApprovalPanel
from ..handlers.stream import StreamHandler
from ..handlers.commands import CommandRegistry
from ..handlers.tools import ToolExecutor


class ChatScreen(Screen):
    """Main chat interface screen."""

    AUTO_FOCUS = "#chat-input"

    BINDINGS = [
        Binding("ctrl+c", "stop", "Stop"),
        Binding("escape", "back", "Back"),
        Binding("ctrl+l", "clear", "Clear"),
    ]

    def __init__(
        self,
        agent: Dict[str, Any],
        conversation: Optional[Dict[str, Any]] = None,
    ) -> None:
        super().__init__()
        self.agent = agent
        self.conversation = conversation
        self.conversation_id: Optional[int] = conversation["id"] if conversation else None
        self.is_processing = False
        self._current_stream: Optional[StreamHandler] = None
        self.command_registry = CommandRegistry(self)
        self.tool_executor: Optional[ToolExecutor] = None
        self.messages: List[Dict[str, Any]] = []
        # Tool approval state
        self._pending_tool_event: Optional[asyncio.Event] = None
        self._tool_decision: Optional[str] = None
        self._current_tool_panel: Optional[ToolApprovalPanel] = None

    def compose(self) -> ComposeResult:
        message_list = VerticalScroll(id="message-list")
        message_list.can_focus = False
        yield StatusBar(self.agent, id="status-bar")
        yield message_list
        yield Input(placeholder="Type your message... (/ for commands)", id="chat-input")

    async def on_mount(self) -> None:
        # Initialize tool executor using app-level tool registry
        self.tool_executor = ToolExecutor(
            tools=self.app._tools,
            client=self.app.client,
            on_message=self.add_system_message,
        )

        if self.conversation:
            asyncio.create_task(self._load_history())
        else:
            asyncio.create_task(self._create_conversation())

    # ── Conversation lifecycle ──────────────────────────────────────

    async def _create_conversation(self) -> None:
        status = self.query_one("#status-bar", StatusBar)
        status.set_status("Creating conversation...")

        try:
            loop = asyncio.get_event_loop()
            response = await loop.run_in_executor(
                None,
                lambda: self.app.client.create_conversation(
                    self.agent["id"],
                    client_type=self.app._client_type,
                    client_tool_schemas=self.app._tool_schemas,
                ),
            )
            self.conversation = response.get("data", response)
            self.conversation_id = self.conversation["id"]
            status.set_status("Connected")

        except Exception as e:
            status.set_status(f"Error: {e}", error=True)

    async def _load_history(self) -> None:
        if not self.conversation:
            return

        message_list = self.query_one("#message-list", VerticalScroll)
        messages = self.conversation.get("messages", [])

        for msg in messages:
            role = msg.get("role", "")
            content = msg.get("content", "")
            thinking = msg.get("thinking", "")

            if role == "user":
                message_list.mount(ChatMessage(content, role="user"))
            elif role == "assistant":
                if thinking:
                    block = ThinkingBlock(thinking)
                    block.finalize()
                    message_list.mount(block)
                if content:
                    message_list.mount(ChatMessage(content, role="assistant"))

        message_list.scroll_end(animate=False)

    # ── Input handling ──────────────────────────────────────────────

    async def on_input_submitted(self, event: Input.Submitted) -> None:
        if event.input.id != "chat-input":
            return

        message = event.input.value.strip()
        if not message:
            return

        event.input.value = ""

        if message.startswith("/"):
            await self.command_registry.handle(message)
            return

        await self.send_message(message)

    # ── Send message ────────────────────────────────────────────────

    async def send_message(self, content: str) -> None:
        if self.is_processing:
            return

        if not self.conversation_id:
            await self._create_conversation()
            if not self.conversation_id:
                return

        self.is_processing = True
        status = self.query_one("#status-bar", StatusBar)
        message_list = self.query_one("#message-list", VerticalScroll)
        chat_input = self.query_one("#chat-input", Input)

        # Show user message
        message_list.mount(ChatMessage(content, role="user"))
        message_list.scroll_end()

        chat_input.disabled = True
        status.set_status("Thinking...")

        # Run in a background task so the event loop stays free for
        # repaints, key handling, and widget updates during streaming.
        asyncio.create_task(self._send_and_stream(content))

    async def _send_and_stream(self, content: str) -> None:
        """Send message to API and stream the response (background task)."""
        status = self.query_one("#status-bar", StatusBar)
        message_list = self.query_one("#message-list", VerticalScroll)
        chat_input = self.query_one("#chat-input", Input)

        try:
            loop = asyncio.get_event_loop()
            response = await loop.run_in_executor(
                None,
                self.app.client.send_message,
                self.conversation_id,
                content,
            )
            await self._handle_response(response)

        except Exception as e:
            status.set_status(f"Error: {e}", error=True)
            message_list.mount(ChatMessage(str(e), role="error"))

        finally:
            self.is_processing = False
            chat_input.disabled = False
            chat_input.focus()
            status.set_status("Connected")

    # ── SSE streaming ───────────────────────────────────────────────

    async def _handle_response(self, initial_response: Dict[str, Any]) -> None:
        status = self.query_one("#status-bar", StatusBar)
        message_list = self.query_one("#message-list", VerticalScroll)

        # Create SSE handler
        handler = StreamHandler(self.app.client, self.conversation_id)
        self._current_stream = handler

        # Create placeholder for streaming response
        response_widget = ChatMessage("", role="assistant", streaming=True)
        message_list.mount(response_widget)
        message_list.anchor()

        accumulated_thinking = ""
        thinking_widget = None
        md_stream = None
        content_started = False

        try:
            md_widget = response_widget.get_markdown_widget()
            md_stream = Markdown.get_stream(md_widget)

            async for event_type, data in handler.stream():
                if event_type == "text_chunk":
                    chunk = data.get("chunk", "")
                    chunk_type = data.get("type", "content")

                    if chunk_type == "thinking":
                        accumulated_thinking += chunk
                        if not thinking_widget:
                            thinking_widget = ThinkingBlock(accumulated_thinking)
                            message_list.mount(thinking_widget, before=response_widget)
                        else:
                            thinking_widget.update_content(accumulated_thinking)
                    else:
                        if thinking_widget and not content_started:
                            thinking_widget.finalize()
                        content_started = True
                        await md_stream.write(chunk)

                elif event_type == "tool_request":
                    tool_request = data.get("tool_request")
                    if tool_request:
                        status.set_status(f"Tool: {tool_request.get('name', 'unknown')}")

                        if md_stream:
                            await md_stream.stop()
                            md_stream = None

                        approved = await self._handle_tool_request(tool_request)

                        if approved:
                            status.set_status("Thinking...")
                            md_stream = Markdown.get_stream(md_widget)
                        else:
                            status.set_status("Tool rejected")

                elif event_type == "completed":
                    if thinking_widget:
                        thinking_widget.finalize()
                    break

                elif event_type == "failed":
                    if thinking_widget:
                        thinking_widget.finalize()
                    error = data.get("error", "Unknown error")
                    response_widget.update_content(f"[red]Error: {error}[/red]")
                    break

                elif event_type == "cancelled":
                    if thinking_widget:
                        thinking_widget.finalize()
                    break

        except Exception as e:
            response_widget.update_content(f"[red]Stream error: {e}[/red]")

        finally:
            if md_stream:
                await md_stream.stop()
            response_widget.set_streaming(False)
            self._current_stream = None

    # ── Tool approval ───────────────────────────────────────────────

    async def _handle_tool_request(self, tool_request: Dict[str, Any]) -> bool:
        if self.app.auto_approve_tools:
            await self.tool_executor.execute(self.conversation_id, tool_request)
            return True

        message_list = self.query_one("#message-list", VerticalScroll)

        # Show inline approval panel and wait for user decision
        self._pending_tool_event = asyncio.Event()
        self._tool_decision = None
        panel = ToolApprovalPanel(tool_request)
        self._current_tool_panel = panel
        message_list.mount(panel)
        message_list.scroll_end()
        panel.focus()

        await self._pending_tool_event.wait()
        decision = self._tool_decision

        # Clean up panel
        if self._current_tool_panel:
            self._current_tool_panel.remove()
            self._current_tool_panel = None
        self._pending_tool_event = None

        if decision == "yes":
            await self.tool_executor.execute(self.conversation_id, tool_request)
            return True
        elif decision == "all":
            self.app.auto_approve_tools = True
            await self.tool_executor.execute(self.conversation_id, tool_request)
            return True
        else:
            await self.tool_executor.reject(self.conversation_id, tool_request)
            return False

    async def on_tool_approval_panel_approved(self, event: ToolApprovalPanel.Approved) -> None:
        self._tool_decision = "yes"
        if self._pending_tool_event:
            self._pending_tool_event.set()

    async def on_tool_approval_panel_rejected(self, event: ToolApprovalPanel.Rejected) -> None:
        self._tool_decision = "no"
        if self._pending_tool_event:
            self._pending_tool_event.set()

    async def on_tool_approval_panel_approve_all(self, event: ToolApprovalPanel.ApproveAll) -> None:
        self._tool_decision = "all"
        if self._pending_tool_event:
            self._pending_tool_event.set()

    # ── Actions ─────────────────────────────────────────────────────

    async def stop_operation(self) -> None:
        if self._current_stream:
            self._current_stream.close()

        if self.is_processing and self.conversation_id:
            try:
                loop = asyncio.get_event_loop()
                await loop.run_in_executor(
                    None,
                    self.app.client.stop_conversation,
                    self.conversation_id,
                )
            except Exception:
                pass

        self.is_processing = False
        self.query_one("#status-bar", StatusBar).set_status("Stopped")

    async def action_stop(self) -> None:
        await self.stop_operation()

    async def action_back(self) -> None:
        await self.stop_operation()
        self.app.pop_screen()

    async def action_clear(self) -> None:
        self.query_one("#message-list", VerticalScroll).remove_children()
        self.messages = []

    def add_system_message(self, content: str) -> None:
        message_list = self.query_one("#message-list", VerticalScroll)
        message_list.mount(ChatMessage(content, role="system"))
        message_list.scroll_end()
