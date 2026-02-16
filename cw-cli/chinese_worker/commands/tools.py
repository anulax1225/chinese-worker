"""Tool management commands."""

import os
import click
from rich.console import Console
from rich.panel import Panel
from rich.prompt import Confirm, Prompt
from rich.table import Table
from rich.progress import Progress, SpinnerColumn, TextColumn

from ..api import APIClient, AuthManager

console = Console()


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


@click.group()
def tools():
    """Manage tools."""
    pass


@tools.command("list")
@click.option("--type", "type_filter", type=click.Choice(["api", "function", "command", "builtin"]), help="Filter by type")
@click.option("--no-builtin", is_flag=True, help="Exclude built-in tools")
@click.option("--search", help="Search query")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_tools(type_filter: str, no_builtin: bool, search: str, api_url: str):
    """List all tools."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        tools_list = client.list_tools(
            include_builtin=not no_builtin,
            type_filter=type_filter,
            search=search,
        )

        if not tools_list:
            console.print("[yellow]![/yellow] No tools found")
            return

        table = Table(
            title=f"Tools ({len(tools_list)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("ID", style="cyan", width=6)
        table.add_column("Name", width=25)
        table.add_column("Type", width=10)
        table.add_column("Description", width=40)

        for tool in tools_list:
            tool_type = tool.get("type", "unknown")
            type_style = {
                "builtin": "dim",
                "api": "green",
                "function": "blue",
                "command": "yellow",
            }.get(tool_type, "")

            description = tool.get("description", "")[:40]
            if len(tool.get("description", "")) > 40:
                description += "..."

            table.add_row(
                str(tool.get("id", "-")),
                tool["name"],
                f"[{type_style}]{tool_type}[/{type_style}]" if type_style else tool_type,
                description,
            )

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list tools: {str(e)}")
        raise click.Abort()


@tools.command("show")
@click.argument("tool_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_tool(tool_id: int, api_url: str):
    """Show tool details."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_tool(tool_id)
        tool = response.get("data", response)

        details = f"[bold]{tool['name']}[/bold]\n"
        details += f"Type: {tool.get('type', 'unknown')}\n"
        if tool.get("description"):
            details += f"\n{tool['description']}\n"

        # Show configuration if present
        config = tool.get("configuration", {})
        if config:
            details += "\nConfiguration:"
            for key, value in config.items():
                if key not in ["headers", "api_key"]:  # Don't show sensitive data
                    details += f"\n  {key}: {value}"

        console.print(Panel(
            details,
            title=f"Tool #{tool.get('id', tool_id)}",
            border_style="blue"
        ))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get tool: {str(e)}")
        raise click.Abort()


@tools.command("create")
@click.option("--name", prompt=True, help="Tool name")
@click.option("--type", "tool_type", type=click.Choice(["api", "function", "command"]), prompt=True, help="Tool type")
@click.option("--description", prompt=True, default="", help="Tool description")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def create_tool(name: str, tool_type: str, description: str, api_url: str):
    """Create a new tool."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Build configuration based on type
        configuration = {}

        if tool_type == "api":
            configuration["url"] = Prompt.ask("API URL")
            configuration["method"] = Prompt.ask("HTTP Method", default="GET")
        elif tool_type == "command":
            configuration["command"] = Prompt.ask("Command template")
        elif tool_type == "function":
            configuration["code"] = Prompt.ask("Function code (or edit later)")

        data = {
            "name": name,
            "type": tool_type,
            "description": description,
            "configuration": configuration,
        }

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Creating tool...", total=None)
            response = client.create_tool(data)

        tool = response.get("data", response)
        console.print(f"[green]✓[/green] Tool created successfully (ID: {tool.get('id', 'N/A')})")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to create tool: {str(e)}")
        raise click.Abort()


@tools.command("edit")
@click.argument("tool_id", type=int)
@click.option("--name", help="New tool name")
@click.option("--description", help="New description")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def edit_tool(tool_id: int, name: str, description: str, api_url: str):
    """Edit a tool."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Get current tool data
        response = client.get_tool(tool_id)
        tool = response.get("data", response)

        # Prompt for values if not provided
        if name is None:
            name = Prompt.ask("Name", default=tool["name"])
        if description is None:
            description = Prompt.ask("Description", default=tool.get("description", ""))

        data = {
            "name": name,
            "description": description,
        }

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Updating tool...", total=None)
            client.update_tool(tool_id, data)

        console.print(f"[green]✓[/green] Tool {tool_id} updated successfully")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to update tool: {str(e)}")
        raise click.Abort()


@tools.command("delete")
@click.argument("tool_id", type=int)
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_tool(tool_id: int, force: bool, api_url: str):
    """Delete a tool."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        if not force:
            response = client.get_tool(tool_id)
            tool = response.get("data", response)

            if not Confirm.ask(f"Delete tool '{tool['name']}'?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting tool...", total=None)
            client.delete_tool(tool_id)

        console.print(f"[green]✓[/green] Tool {tool_id} deleted")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete tool: {str(e)}")
        raise click.Abort()
