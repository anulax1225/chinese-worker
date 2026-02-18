"""Main chat screen."""

import asyncio
from typing import Optional, Dict, Any, List

from textual.app import ComposeResult
from textual.containers import VerticalScroll
from textual.screen import Screen
from textual.widgets import Input, Static, Markdown
from textual.binding import Binding

from ..widgets import StatusBar, MessageWidget, ToolApprovalModal, ToolApprovalPanel, ThinkingBlock
from ..handlers import SSEHandler, CommandHandler, ToolHandler


class ChatScreen(Screen):
    """Main chat interface screen."""

    # Auto-focus the chat input when screen is activated
    AUTO_FOCUS = "#chat-input"

    BINDINGS = [
        Binding("ctrl+c", "stop", "Stop"),
        Binding("escape", "agents", "Agents"),
        Binding("ctrl+l", "clear", "Clear"),
    ]

    def __init__(self, agent: Dict[str, Any], conversation: Optional[Dict[str, Any]] = None) -> None:
        super().__init__()
        self.agent = agent
        self.conversation = conversation
        self.conversation_id: Optional[int] = conversation["id"] if conversation else None
        self.is_processing = False
        self.current_sse_handler: Optional[SSEHandler] = None
        self.command_handler = CommandHandler(self)
        self.tool_handler: Optional[ToolHandler] = None
        self.messages: List[Dict[str, Any]] = []
        # Tool approval state
        self._pending_tool_approval: Optional[asyncio.Event] = None
        self._tool_approval_result: Optional[str] = None
        self._current_tool_panel: Optional[ToolApprovalPanel] = None

    def compose(self) -> ComposeResult:
        """Create child widgets."""
        message_list = VerticalScroll(id="message-list")
        message_list.can_focus = False  # Prevent message list from intercepting keys
        yield StatusBar(self.agent, id="status-bar")
        yield message_list
        yield Input(placeholder="Type your message... (/ for commands)", id="chat-input")

    async def on_mount(self) -> None:
        """Initialize chat when mounted."""
        # Initialize tool handler
        from ...cli import get_platform_tools, get_tool_schemas, get_client_type
        tools = get_platform_tools()
        self.tool_handler = ToolHandler(tools, self.app.client, self)

        # Load or create conversation in background
        if self.conversation:
            asyncio.create_task(self.load_conversation_history())
        else:
            asyncio.create_task(self.create_conversation())

    async def create_conversation(self) -> None:
        """Create a new conversation."""
        status = self.query_one("#status-bar", StatusBar)
        status.set_status("Creating conversation...")

        try:
            from ...cli import get_platform_tools, get_tool_schemas, get_client_type

            tools = get_platform_tools()
            tool_schemas = get_tool_schemas(tools)
            client_type = get_client_type()

            # Run blocking API call in executor to avoid blocking event loop
            loop = asyncio.get_event_loop()
            response = await loop.run_in_executor(
                None,
                lambda: self.app.client.create_conversation(
                    self.agent["id"],
                    client_type=client_type,
                    client_tool_schemas=tool_schemas,
                ),
            )
            self.conversation = response.get("data", response)
            self.conversation_id = self.conversation["id"]
            status.set_status("Connected")

        except Exception as e:
            status.set_status(f"Error: {str(e)}", error=True)

    async def load_conversation_history(self) -> None:
        """Load existing conversation messages."""
        if not self.conversation:
            return

        message_list = self.query_one("#message-list", VerticalScroll)
        messages = self.conversation.get("messages", [])

        for msg in messages:
            role = msg.get("role", "")
            content = msg.get("content", "")
            thinking = msg.get("thinking", "")

            if role == "user":
                widget = MessageWidget(content, role="user")
                message_list.mount(widget)
            elif role == "assistant":
                if thinking:
                    thinking_widget = ThinkingBlock(thinking)
                    thinking_widget.finalize()
                    message_list.mount(thinking_widget)
                if content:
                    widget = MessageWidget(content, role="assistant")
                    message_list.mount(widget)

        # Scroll to bottom
        message_list.scroll_end(animate=False)

    async def on_input_submitted(self, event: Input.Submitted) -> None:
        """Handle message submission."""
        if event.input.id != "chat-input":
            return

        message = event.input.value.strip()
        if not message:
            return

        event.input.value = ""

        # Check for slash command
        if message.startswith("/"):
            await self.command_handler.handle(message)
            return

        # Send message
        await self.send_message(message)

    async def send_message(self, content: str) -> None:
        """Send a message and handle response."""
        if self.is_processing:
            return

        if not self.conversation_id:
            await self.create_conversation()
            if not self.conversation_id:
                return

        self.is_processing = True
        status = self.query_one("#status-bar", StatusBar)
        message_list = self.query_one("#message-list", VerticalScroll)
        chat_input = self.query_one("#chat-input", Input)

        # Add user message to display
        user_widget = MessageWidget(content, role="user")
        message_list.mount(user_widget)
        message_list.scroll_end()

        # Disable input while processing
        chat_input.disabled = True
        status.set_status("Thinking...")

        try:
            # Send message to API (run in executor to avoid blocking)
            loop = asyncio.get_event_loop()
            response = await loop.run_in_executor(
                None,
                self.app.client.send_message,
                self.conversation_id,
                content,
            )

            # Handle response with SSE streaming
            await self.handle_response(response)

        except Exception as e:
            status.set_status(f"Error: {str(e)}", error=True)
            error_widget = MessageWidget(f"Error: {str(e)}", role="error")
            message_list.mount(error_widget)

        finally:
            self.is_processing = False
            chat_input.disabled = False
            chat_input.focus()
            status.set_status("Connected")

    async def handle_response(self, initial_response: Dict[str, Any]) -> None:
        """Handle conversation response with SSE streaming."""
        status = self.query_one("#status-bar", StatusBar)
        message_list = self.query_one("#message-list", VerticalScroll)

        # Create SSE handler
        self.current_sse_handler = SSEHandler(
            self.app.client,
            self.conversation_id,
        )

        # Create placeholder for streaming response
        response_widget = MessageWidget("", role="assistant", streaming=True)
        message_list.mount(response_widget)

        # Enable auto-scroll to bottom
        message_list.anchor()

        accumulated_thinking = ""
        thinking_widget = None
        md_stream = None
        content_started = False

        try:
            # Get the Markdown widget and create a stream for flicker-free rendering
            md_widget = response_widget.get_markdown_widget()
            md_stream = Markdown.get_stream(md_widget)

            async for event_type, data in self.current_sse_handler.stream():
                if event_type == "text_chunk":
                    chunk = data.get("chunk", "")
                    chunk_type = data.get("type", "content")

                    if chunk_type == "thinking":
                        accumulated_thinking += chunk
                        # Create or update thinking widget
                        if not thinking_widget:
                            thinking_widget = ThinkingBlock(accumulated_thinking)
                            # Insert thinking widget before response widget
                            message_list.mount(thinking_widget, before=response_widget)
                        else:
                            thinking_widget.update_content(accumulated_thinking)
                    else:
                        # Finalize thinking block when content starts
                        if thinking_widget and not content_started:
                            thinking_widget.finalize()
                        content_started = True
                        # Stream chunk directly to Markdown widget
                        await md_stream.write(chunk)

                elif event_type == "tool_request":
                    tool_request = data.get("tool_request")
                    if tool_request:
                        status.set_status(f"Tool: {tool_request.get('name', 'unknown')}")

                        # Stop current stream before handling tool
                        if md_stream:
                            await md_stream.stop()
                            md_stream = None

                        # Handle tool execution
                        approved = await self.handle_tool_request(tool_request)

                        if approved:
                            # Continue streaming after tool execution
                            status.set_status("Thinking...")
                            # Create new stream to continue
                            md_stream = Markdown.get_stream(md_widget)
                        else:
                            status.set_status("Tool rejected")

                elif event_type == "completed":
                    if thinking_widget:
                        thinking_widget.finalize()
                    if md_stream:
                        await md_stream.stop()
                        md_stream = None
                    response_widget.set_streaming(False)
                    break

                elif event_type == "failed":
                    if thinking_widget:
                        thinking_widget.finalize()
                    if md_stream:
                        await md_stream.stop()
                        md_stream = None
                    error = data.get("error", "Unknown error")
                    response_widget.update_content(f"[red]Error: {error}[/red]")
                    response_widget.set_streaming(False)
                    break

                elif event_type == "cancelled":
                    if thinking_widget:
                        thinking_widget.finalize()
                    if md_stream:
                        await md_stream.stop()
                        md_stream = None
                    response_widget.set_streaming(False)
                    break

        except Exception as e:
            if md_stream:
                await md_stream.stop()
            response_widget.update_content(f"[red]Stream error: {str(e)}[/red]")
            response_widget.set_streaming(False)

        finally:
            self.current_sse_handler = None

    async def handle_tool_request(self, tool_request: Dict[str, Any]) -> bool:
        """Handle a tool execution request."""
        if self.app.auto_approve_tools:
            # Auto-approve mode
            result = await self.tool_handler.execute(tool_request)
            return result

        # Show inline approval panel
        message_list = self.query_one("#message-list", VerticalScroll)

        # Create the approval panel
        self._pending_tool_approval = asyncio.Event()
        self._tool_approval_result = None
        panel = ToolApprovalPanel(tool_request)
        self._current_tool_panel = panel
        message_list.mount(panel)
        message_list.scroll_end()

        # Focus the panel so it can receive key events
        panel.focus()

        # Wait for user decision
        await self._pending_tool_approval.wait()
        result = self._tool_approval_result

        # Remove the panel
        if self._current_tool_panel:
            self._current_tool_panel.remove()
            self._current_tool_panel = None
        self._pending_tool_approval = None

        if result == "yes":
            await self.tool_handler.execute(tool_request)
            return True
        elif result == "all":
            self.app.auto_approve_tools = True
            await self.tool_handler.execute(tool_request)
            return True
        else:
            # Rejected - submit rejection
            await self.tool_handler.reject(tool_request)
            return False

    async def on_tool_approval_panel_approved(self, event: ToolApprovalPanel.Approved) -> None:
        """Handle tool approval."""
        self._tool_approval_result = "yes"
        if self._pending_tool_approval:
            self._pending_tool_approval.set()

    async def on_tool_approval_panel_rejected(self, event: ToolApprovalPanel.Rejected) -> None:
        """Handle tool rejection."""
        self._tool_approval_result = "no"
        if self._pending_tool_approval:
            self._pending_tool_approval.set()

    async def on_tool_approval_panel_approve_all(self, event: ToolApprovalPanel.ApproveAll) -> None:
        """Handle approve all."""
        self._tool_approval_result = "all"
        if self._pending_tool_approval:
            self._pending_tool_approval.set()

    async def stop_operation(self) -> None:
        """Stop current operation."""
        if self.current_sse_handler:
            self.current_sse_handler.close()

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
        status = self.query_one("#status-bar", StatusBar)
        status.set_status("Stopped")

    async def action_stop(self) -> None:
        """Stop current operation."""
        await self.stop_operation()

    async def action_agents(self) -> None:
        """Go back to agent selection."""
        await self.stop_operation()
        await self.app.switch_to_agent_select()

    async def action_clear(self) -> None:
        """Clear message list."""
        message_list = self.query_one("#message-list", VerticalScroll)
        message_list.remove_children()
        self.messages = []

    def add_system_message(self, content: str) -> None:
        """Add a system message to the chat."""
        message_list = self.query_one("#message-list", VerticalScroll)
        widget = MessageWidget(content, role="system")
        message_list.mount(widget)
        message_list.scroll_end()
