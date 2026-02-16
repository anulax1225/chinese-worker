"""Slash command handler for TUI."""

from typing import TYPE_CHECKING

if TYPE_CHECKING:
    from ..screens.chat import ChatScreen


class CommandHandler:
    """Handler for slash commands in the chat interface."""

    COMMANDS = {
        "/help": "Show available commands",
        "/agents": "Switch to agent selection",
        "/clear": "Clear message history",
        "/stop": "Stop current generation",
        "/tools": "List available tools",
        "/approve-all": "Auto-approve all tool executions",
        "/exit": "Exit the application",
        "/quit": "Exit the application",
    }

    def __init__(self, screen: "ChatScreen") -> None:
        self.screen = screen

    async def handle(self, command: str) -> None:
        """Handle a slash command."""
        cmd = command.strip().lower()
        parts = cmd.split(maxsplit=1)
        cmd_name = parts[0]
        args = parts[1] if len(parts) > 1 else ""

        if cmd_name == "/help":
            await self.cmd_help()
        elif cmd_name == "/agents":
            await self.cmd_agents()
        elif cmd_name == "/clear":
            await self.cmd_clear()
        elif cmd_name == "/stop":
            await self.cmd_stop()
        elif cmd_name == "/tools":
            await self.cmd_tools()
        elif cmd_name == "/approve-all":
            await self.cmd_approve_all()
        elif cmd_name in ("/exit", "/quit"):
            await self.cmd_exit()
        else:
            self.screen.add_system_message(f"Unknown command: {cmd_name}. Type /help for available commands.")

    async def cmd_help(self) -> None:
        """Show help message."""
        lines = ["[bold]Available Commands:[/bold]"]
        for cmd, desc in self.COMMANDS.items():
            lines.append(f"  [cyan]{cmd}[/cyan] - {desc}")
        self.screen.add_system_message("\n".join(lines))

    async def cmd_agents(self) -> None:
        """Switch to agent selection."""
        await self.screen.action_agents()

    async def cmd_clear(self) -> None:
        """Clear message history."""
        await self.screen.action_clear()
        self.screen.add_system_message("Chat cleared.")

    async def cmd_stop(self) -> None:
        """Stop current generation."""
        await self.screen.stop_operation()
        self.screen.add_system_message("Operation stopped.")

    async def cmd_tools(self) -> None:
        """List available tools."""
        if self.screen.tool_handler:
            tools = self.screen.tool_handler.get_tool_names()
            lines = ["[bold]Available Tools:[/bold]"]
            for name in sorted(tools):
                lines.append(f"  - [cyan]{name}[/cyan]")
            self.screen.add_system_message("\n".join(lines))
        else:
            self.screen.add_system_message("Tool handler not initialized.")

    async def cmd_approve_all(self) -> None:
        """Enable auto-approve mode."""
        self.screen.app.auto_approve_tools = True
        self.screen.add_system_message("[green]Auto-approve mode enabled.[/green] All future tools will be automatically approved.")

    async def cmd_exit(self) -> None:
        """Exit the application."""
        self.screen.app.exit()
