"""Slash command registry for TUI."""

from typing import TYPE_CHECKING

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
    }

    def __init__(self, screen: "ChatScreen") -> None:
        self.screen = screen

    async def handle(self, command: str) -> None:
        cmd_name = command.strip().lower().split(maxsplit=1)[0]

        handler = {
            "/help": self._cmd_help,
            "/agents": self._cmd_agents,
            "/clear": self._cmd_clear,
            "/stop": self._cmd_stop,
            "/tools": self._cmd_tools,
            "/approve-all": self._cmd_approve_all,
            "/exit": self._cmd_exit,
            "/quit": self._cmd_exit,
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
