"""Main chat screen."""

import asyncio
from typing import Optional, Dict, Any, List

from textual.app import ComposeResult
from textual.containers import Horizontal, VerticalScroll
from textual.screen import Screen
from textual.widgets import Input, Markdown
from textual.binding import Binding

from ..widgets.message import ChatMessage
from ..widgets.status_bar import StatusBar
from ..widgets.thinking import ThinkingBlock
from ..widgets.tool_panel import ToolApprovalPanel
from ..widgets.tool_status import ToolStatusWidget
from ..widgets.conversation_sidebar import ConversationSidebar
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
        Binding("ctrl+b", "toggle_sidebar", "Sidebar"),
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
        # Sidebar state
        self._sidebar_visible = False

    def compose(self) -> ComposeResult:
        yield StatusBar(self.agent, id="status-bar")
        with Horizontal(id="chat-layout"):
            yield ConversationSidebar(self.agent.get("id"), id="sidebar")
            message_list = VerticalScroll(id="message-list")
            message_list.can_focus = False
            yield message_list
        yield Input(placeholder="Type your message... (/ for commands)", id="chat-input")

    async def on_mount(self) -> None:
        # Initialize tool executor using app-level tool registry
        self.tool_executor = ToolExecutor(
            tools=self.app._tools,
            client=self.app.client,
            on_message=self.add_system_message,
        )

        # Update sidebar with current conversation
        sidebar = self.query_one("#sidebar", ConversationSidebar)
        if self.conversation_id:
            sidebar.set_current_conversation(self.conversation_id)

        if self.conversation:
            await self._load_history()
            # Check for pending tools after loading history
            asyncio.create_task(self._check_pending_tools())
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

            # Update sidebar
            sidebar = self.query_one("#sidebar", ConversationSidebar)
            sidebar.set_current_conversation(self.conversation_id)
            if sidebar.is_visible:
                await sidebar.load_conversations()

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

    async def _check_pending_tools(self) -> None:
        """Check for and handle pending tool requests when resuming.

        This handles two scenarios:
        1. Server-side paused state with pending_tool_request field
        2. Unanswered tool calls in the last assistant message
        """
        if not self.conversation:
            return

        status_val = self.conversation.get("status")
        waiting_for = self.conversation.get("waiting_for")
        pending_tool = self.conversation.get("pending_tool_request")

        # Check server-side paused state
        if status_val == "paused" and waiting_for == "tool_result" and pending_tool:
            self.add_system_message("Found pending tool request from previous session")
            approved = await self._handle_tool_request(pending_tool)
            if approved:
                # Reconnect to stream for response
                asyncio.create_task(self._reconnect_stream())
            return

        # Check message history for unanswered tool calls
        messages = self.conversation.get("messages", [])
        if not messages:
            return

        last_msg = messages[-1]
        if last_msg.get("role") != "assistant":
            return

        tool_calls = last_msg.get("tool_calls", [])
        if not tool_calls:
            return

        # Find answered tool call IDs
        answered_ids = {
            m.get("tool_call_id")
            for m in messages
            if m.get("role") == "tool"
        }

        for tc in tool_calls:
            call_id = tc.get("call_id") or tc.get("id")
            if call_id and call_id not in answered_ids:
                self.add_system_message("Found unanswered tool call from previous session")
                tool_request = {
                    "call_id": call_id,
                    "name": tc.get("name"),
                    "arguments": tc.get("arguments", {}),
                }
                approved = await self._handle_tool_request(tool_request)
                if approved:
                    asyncio.create_task(self._reconnect_stream())
                return

    async def _reconnect_stream(self) -> None:
        """Reconnect to SSE stream after tool result submission."""
        self.is_processing = True
        status = self.query_one("#status-bar", StatusBar)
        chat_input = self.query_one("#chat-input", Input)
        chat_input.disabled = True
        status.set_status("Thinking...")

        try:
            await self._handle_response({})
        finally:
            self.is_processing = False
            chat_input.disabled = False
            chat_input.focus()
            status.set_status("Connected")

    async def switch_conversation(self, conversation_id: int) -> None:
        """Switch to a different conversation.

        Args:
            conversation_id: ID of the conversation to switch to
        """
        # Stop current stream
        await self.stop_operation()

        # Clear current messages
        self.query_one("#message-list", VerticalScroll).remove_children()
        self.messages = []

        # Load new conversation
        status = self.query_one("#status-bar", StatusBar)
        status.set_status("Loading...")

        try:
            loop = asyncio.get_event_loop()
            self.conversation = await loop.run_in_executor(
                None,
                self.app.client.get_conversation,
                conversation_id,
            )
            self.conversation_id = conversation_id

            # Load history
            await self._load_history()

            # Update sidebar
            sidebar = self.query_one("#sidebar", ConversationSidebar)
            sidebar.set_current_conversation(conversation_id)

            status.set_status("Connected")

            # Check for pending tools
            await self._check_pending_tools()

        except Exception as e:
            status.set_status(f"Error: {e}", error=True)

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

    # ── SSE streaming (phase-based) ────────────────────────────────
    #
    # The AI chains actions: think → respond → tool → think → respond.
    # Each type change creates a new widget so the conversation shows
    # the full sequence, just like the web app's StreamingPhases.

    async def _handle_response(self, initial_response: Dict[str, Any]) -> None:
        status = self.query_one("#status-bar", StatusBar)
        message_list = self.query_one("#message-list", VerticalScroll)
        message_list.anchor()

        # Phase tracking — persists across SSE reconnections so the UI
        # accumulates widgets from the entire multi-tool conversation.
        current_phase: Optional[str] = None  # "thinking" | "content"
        thinking_widget: Optional[ThinkingBlock] = None
        thinking_text = ""
        content_widget: Optional[ChatMessage] = None
        md_stream = None
        # Map call_id → ToolStatusWidget for in-place updates
        tool_widgets: Dict[str, ToolStatusWidget] = {}

        async def _finish_content_phase() -> None:
            nonlocal md_stream, content_widget
            if md_stream:
                await md_stream.stop()
                md_stream = None
            if content_widget:
                content_widget.set_streaming(False)
                content_widget = None

        def _finish_thinking_phase() -> None:
            nonlocal thinking_widget, thinking_text
            if thinking_widget:
                thinking_widget.finalize()
                thinking_widget = None
            thinking_text = ""

        async def _start_content_phase() -> None:
            nonlocal current_phase, content_widget, md_stream
            _finish_thinking_phase()
            await _finish_content_phase()
            current_phase = "content"
            content_widget = ChatMessage("", role="assistant", streaming=True)
            message_list.mount(content_widget)
            md_widget = content_widget.get_markdown_widget()
            md_stream = Markdown.get_stream(md_widget)

        def _start_thinking_phase() -> None:
            nonlocal current_phase, thinking_widget, thinking_text
            # Don't close content phase here — thinking can precede content
            _finish_thinking_phase()
            current_phase = "thinking"
            thinking_text = ""
            thinking_widget = ThinkingBlock("")
            message_list.mount(thinking_widget)

        # ── SSE reconnection loop ─────────────────────────────
        # The server closes the SSE connection after tool_request events.
        # We must close the handler, process the tool, then reconnect
        # with a fresh StreamHandler — same pattern as cli.py:634-757.
        done = False
        while not done:
            handler = StreamHandler(self.app.client, self.conversation_id)
            self._current_stream = handler

            try:
                async for event_type, data in handler.stream():

                    # ── Text chunks (thinking or content) ───────────
                    if event_type == "text_chunk":
                        chunk = data.get("chunk", "")
                        chunk_type = data.get("type", "content")

                        if chunk_type == "thinking":
                            if current_phase != "thinking":
                                await _finish_content_phase()
                                _start_thinking_phase()
                            thinking_text += chunk
                            if thinking_widget:
                                thinking_widget.update_content(thinking_text)

                        else:  # content
                            if current_phase != "content":
                                await _start_content_phase()
                            if md_stream:
                                await md_stream.write(chunk)

                    # ── Server-side tool executing ──────────────────
                    elif event_type == "tool_executing":
                        await _finish_content_phase()
                        _finish_thinking_phase()
                        current_phase = None
                        tool = data.get("tool", {})
                        t_name = tool.get("name", "unknown")
                        t_call_id = tool.get("call_id", "")
                        status.set_status(f"Tool: {t_name}")
                        tw = ToolStatusWidget(t_name, call_id=t_call_id)
                        if t_call_id:
                            tool_widgets[t_call_id] = tw
                        message_list.mount(tw)

                    # ── Server-side tool completed ──────────────────
                    elif event_type == "tool_completed":
                        call_id = data.get("call_id", "")
                        t_name = data.get("name", "")
                        t_success = data.get("success", True)
                        t_content = data.get("content", "")
                        tw = tool_widgets.get(call_id)
                        if tw:
                            tw.complete(t_success, t_content)
                        else:
                            # No matching executing widget — show standalone
                            tw = ToolStatusWidget(t_name or "tool", call_id=call_id)
                            tw.complete(t_success, t_content)
                            message_list.mount(tw)
                        status.set_status("Thinking...")

                    # ── Client-side tool request (needs approval) ───
                    # Server closes SSE after this event — we must break,
                    # handle the tool, then reconnect with a fresh handler.
                    elif event_type == "tool_request":
                        tool_request = data.get("tool_request")
                        if tool_request:
                            await _finish_content_phase()
                            _finish_thinking_phase()
                            current_phase = None
                            status.set_status(f"Tool: {tool_request.get('name', 'unknown')}")

                            approved = await self._handle_tool_request(tool_request)

                            if approved:
                                status.set_status("Thinking...")
                            else:
                                status.set_status("Tool rejected")
                        # Break inner loop to close handler and reconnect
                        break

                    # ── Status changed ──────────────────────────────
                    elif event_type == "status_changed":
                        new_status = data.get("status", "")
                        if new_status:
                            status.set_status(new_status.replace("_", " ").title())

                    # ── Terminal events ─────────────────────────────
                    elif event_type == "completed":
                        _finish_thinking_phase()
                        done = True
                        break

                    elif event_type == "failed":
                        _finish_thinking_phase()
                        await _finish_content_phase()
                        error = data.get("error", "Unknown error")
                        message_list.mount(ChatMessage(error, role="error"))
                        done = True
                        break

                    elif event_type == "cancelled":
                        _finish_thinking_phase()
                        done = True
                        break

                else:
                    # for/else: stream exhausted without break (no more events)
                    done = True

            except Exception as e:
                message_list.mount(ChatMessage(f"Stream error: {e}", role="error"))
                done = True

            finally:
                handler.close()
                self._current_stream = None

            # Delay before reconnecting so the server can process the tool result
            if not done:
                await asyncio.sleep(0.3)

        # Final cleanup after all reconnection iterations
        if md_stream:
            await md_stream.stop()
        if content_widget:
            content_widget.set_streaming(False)
        _finish_thinking_phase()

    # ── Tool approval ───────────────────────────────────────────────

    async def _handle_tool_request(self, tool_request: Dict[str, Any]) -> bool:
        if self.app.auto_approve_tools:
            await self.tool_executor.execute(self.conversation_id, tool_request)
            return True

        message_list = self.query_one("#message-list", VerticalScroll)
        chat_input = self.query_one("#chat-input", Input)

        # Disable input during tool approval
        chat_input.disabled = True

        # Show inline approval panel and wait for user decision
        self._pending_tool_event = asyncio.Event()
        self._tool_decision = None
        panel = ToolApprovalPanel(tool_request)
        self._current_tool_panel = panel
        message_list.mount(panel)
        message_list.scroll_end()

        # Use call_after_refresh for reliable focus timing
        self.call_after_refresh(panel.focus)

        await self._pending_tool_event.wait()
        decision = self._tool_decision

        # Clean up panel
        if self._current_tool_panel:
            self._current_tool_panel.remove()
            self._current_tool_panel = None
        self._pending_tool_event = None

        # Re-enable input
        chat_input.disabled = False

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

    # ── Sidebar message handlers ─────────────────────────────────────

    async def on_conversation_sidebar_new_conversation(
        self, event: ConversationSidebar.NewConversation
    ) -> None:
        """Handle new conversation request from sidebar."""
        event.stop()
        await self.stop_operation()
        self.query_one("#message-list", VerticalScroll).remove_children()
        self.messages = []
        self.conversation = None
        self.conversation_id = None
        await self._create_conversation()

    async def on_conversation_sidebar_switch_conversation(
        self, event: ConversationSidebar.SwitchConversation
    ) -> None:
        """Handle conversation switch request from sidebar."""
        event.stop()
        await self.switch_conversation(event.conversation_id)

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

    async def action_toggle_sidebar(self) -> None:
        """Toggle the conversation sidebar."""
        sidebar = self.query_one("#sidebar", ConversationSidebar)
        sidebar.toggle()

    def add_system_message(self, content: str) -> None:
        message_list = self.query_one("#message-list", VerticalScroll)
        message_list.mount(ChatMessage(content, role="system"))
        message_list.scroll_end()
