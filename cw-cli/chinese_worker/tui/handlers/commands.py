"""Slash command registry for TUI."""

import asyncio
from typing import TYPE_CHECKING

from ..utils.time import relative_time

if TYPE_CHECKING:
    from ..screens.chat import ChatScreen


class CommandRegistry:
    """Handler for slash commands in the chat interface."""

    COMMANDS = {
        "/help": "Show available commands",
        "/agents": "Go back to agent selection",
        "/clear": "Clear message history",
        "/stop": "Stop current generation",
        "/tools": "List available tools",
        "/approve-all": "Auto-approve all tool executions",
        "/exit": "Exit the application",
        "/conversations": "Open conversation list",
        "/sidebar": "Toggle conversation sidebar",
        "/new": "Start new conversation",
        "/switch <id>": "Switch to conversation by ID",
        "/delete": "Delete current conversation",
        "/info": "Show current conversation info",
    }

    def __init__(self, screen: "ChatScreen") -> None:
        self.screen = screen

    async def handle(self, command: str) -> None:
        parts = command.strip().split(maxsplit=1)
        cmd_name = parts[0].lower()
        args = parts[1] if len(parts) > 1 else ""

        handler = {
            "/help": self._cmd_help,
            "/agents": self._cmd_agents,
            "/clear": self._cmd_clear,
            "/stop": self._cmd_stop,
            "/tools": self._cmd_tools,
            "/approve-all": self._cmd_approve_all,
            "/exit": self._cmd_exit,
            "/quit": self._cmd_exit,
            "/conversations": self._cmd_conversations,
            "/sidebar": self._cmd_sidebar,
            "/new": self._cmd_new,
            "/switch": lambda: self._cmd_switch(args),
            "/delete": self._cmd_delete,
            "/info": self._cmd_info,
        }.get(cmd_name)

        if handler:
            await handler()
        else:
            self.screen.add_system_message(
                f"Unknown command: {cmd_name}. Type /help for available commands."
            )

    async def _cmd_help(self) -> None:
        lines = ["[bold]Available Commands:[/bold]"]
        for cmd, desc in self.COMMANDS.items():
            lines.append(f"  [#89dceb]{cmd}[/#89dceb] - {desc}")
        self.screen.add_system_message("\n".join(lines))

    async def _cmd_agents(self) -> None:
        await self.screen.action_back()

    async def _cmd_clear(self) -> None:
        await self.screen.action_clear()
        self.screen.add_system_message("Chat cleared.")

    async def _cmd_stop(self) -> None:
        await self.screen.stop_operation()
        self.screen.add_system_message("Operation stopped.")

    async def _cmd_tools(self) -> None:
        if self.screen.tool_executor:
            tools = self.screen.tool_executor.get_tool_names()
            lines = ["[bold]Available Tools:[/bold]"]
            for name in sorted(tools):
                lines.append(f"  - [#89dceb]{name}[/#89dceb]")
            self.screen.add_system_message("\n".join(lines))
        else:
            self.screen.add_system_message("Tool handler not initialized.")

    async def _cmd_approve_all(self) -> None:
        self.screen.app.auto_approve_tools = True
        self.screen.add_system_message(
            "[#a6e3a1]Auto-approve mode enabled.[/#a6e3a1] All future tools will be automatically approved."
        )

    async def _cmd_exit(self) -> None:
        self.screen.app.exit()

    async def _cmd_conversations(self) -> None:
        """Open the conversation list screen."""
        from ..screens.conversations import ConversationListScreen

        self.screen.app.push_screen(
            ConversationListScreen(self.screen.agent.get("id"))
        )

    async def _cmd_sidebar(self) -> None:
        """Toggle the conversation sidebar."""
        await self.screen.action_toggle_sidebar()

    async def _cmd_new(self) -> None:
        """Start a new conversation with the current agent."""
        from textual.containers import VerticalScroll

        await self.screen.stop_operation()
        self.screen.query_one("#message-list", VerticalScroll).remove_children()
        self.screen.messages = []
        self.screen.conversation = None
        self.screen.conversation_id = None
        await self.screen._create_conversation()
        self.screen.add_system_message("Started new conversation.")

    async def _cmd_switch(self, args: str) -> None:
        """Switch to a conversation by ID."""
        args = args.strip()
        if not args:
            self.screen.add_system_message("Usage: /switch <conversation_id>")
            return

        try:
            conv_id = int(args)
            await self.screen.switch_conversation(conv_id)
        except ValueError:
            self.screen.add_system_message(
                f"Invalid conversation ID: {args}. Must be a number."
            )

    async def _cmd_delete(self) -> None:
        """Delete the current conversation."""
        if not self.screen.conversation_id:
            self.screen.add_system_message("No active conversation to delete.")
            return

        conv_id = self.screen.conversation_id

        try:
            loop = asyncio.get_event_loop()
            await loop.run_in_executor(
                None,
                self.screen.app.client.delete_conversation,
                conv_id,
            )
            self.screen.add_system_message(f"Deleted conversation #{conv_id}")
            # Go back to agent list
            await self.screen.action_back()
        except Exception as e:
            self.screen.add_system_message(f"[#f38ba8]Error: {e}[/#f38ba8]")

    async def _cmd_info(self) -> None:
        """Show current conversation metadata."""
        if not self.screen.conversation:
            self.screen.add_system_message("No active conversation.")
            return

        conv = self.screen.conversation
        msg_count = len(conv.get("messages", []))
        created = relative_time(conv.get("created_at"))
        updated = relative_time(conv.get("updated_at"))

        info = [
            f"[bold]Conversation #{conv.get('id', '?')}[/bold]",
            f"  Status: {conv.get('status', 'unknown')}",
            f"  Messages: {msg_count}",
            f"  Created: {created}",
            f"  Updated: {updated}",
        ]

        # Show waiting_for if present
        waiting_for = conv.get("waiting_for")
        if waiting_for:
            info.append(f"  Waiting for: {waiting_for}")

        self.screen.add_system_message("\n".join(info))
