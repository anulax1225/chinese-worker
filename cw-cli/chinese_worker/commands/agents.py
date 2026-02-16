"""Agent management commands."""

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


def require_auth(func):
    """Decorator to require authentication."""
    def wrapper(*args, **kwargs):
        if not AuthManager.is_authenticated():
            console.print("[yellow]![/yellow] You are not logged in")
            console.print("Run 'cw login' to authenticate")
            raise click.Abort()
        return func(*args, **kwargs)
    return wrapper


@click.group()
def agents():
    """Manage AI agents."""
    pass


@agents.command("list")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_agents(api_url: str):
    """List all agents."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        agents_list = client.list_agents()

        if not agents_list:
            console.print("[yellow]![/yellow] You don't have any agents yet")
            console.print("Create one with: cw agents create")
            return

        table = Table(
            title=f"Agents ({len(agents_list)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("ID", style="cyan", width=6)
        table.add_column("Name", width=25)
        table.add_column("Backend", width=12)
        table.add_column("Model", width=20)
        table.add_column("Tools", width=8)

        for agent in agents_list:
            tools_count = len(agent.get("tools", []))
            table.add_row(
                str(agent["id"]),
                agent["name"],
                agent.get("ai_backend", ""),
                agent.get("model", "")[:20],
                str(tools_count),
            )

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list agents: {str(e)}")
        raise click.Abort()


@agents.command("show")
@click.argument("agent_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_agent(agent_id: int, api_url: str):
    """Show agent details."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_agent(agent_id)
        agent = response.get("data", response)

        # Build details
        details = f"[bold]{agent['name']}[/bold]\n"
        if agent.get("description"):
            details += f"{agent['description']}\n"
        details += f"\nBackend: {agent.get('ai_backend', 'N/A')}"
        details += f"\nModel: {agent.get('model', 'N/A')}"

        if agent.get("system_prompt"):
            prompt_name = agent["system_prompt"].get("name", "Custom")
            details += f"\nSystem Prompt: {prompt_name}"

        tools = agent.get("tools", [])
        if tools:
            details += f"\n\nTools ({len(tools)}):"
            for tool in tools:
                details += f"\n  - {tool.get('name', 'Unknown')}"

        console.print(Panel(
            details,
            title=f"Agent #{agent['id']}",
            border_style="blue"
        ))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get agent: {str(e)}")
        raise click.Abort()


@agents.command("create")
@click.option("--name", prompt=True, help="Agent name")
@click.option("--description", prompt=True, default="", help="Agent description")
@click.option("--backend", type=click.Choice(["ollama", "anthropic", "openai", "vllm"]), prompt=True, help="AI backend")
@click.option("--model", prompt=True, help="Model name")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def create_agent(name: str, description: str, backend: str, model: str, api_url: str):
    """Create a new agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        data = {
            "name": name,
            "description": description,
            "ai_backend": backend,
            "model": model,
        }

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Creating agent...", total=None)
            response = client.create_agent(data)

        agent = response.get("data", response)
        console.print(f"[green]✓[/green] Agent created successfully (ID: {agent['id']})")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to create agent: {str(e)}")
        raise click.Abort()


@agents.command("edit")
@click.argument("agent_id", type=int)
@click.option("--name", help="New agent name")
@click.option("--description", help="New description")
@click.option("--model", help="New model")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def edit_agent(agent_id: int, name: str, description: str, model: str, api_url: str):
    """Edit an agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Get current agent data
        response = client.get_agent(agent_id)
        agent = response.get("data", response)

        # Prompt for values if not provided
        if name is None:
            name = Prompt.ask("Name", default=agent["name"])
        if description is None:
            description = Prompt.ask("Description", default=agent.get("description", ""))
        if model is None:
            model = Prompt.ask("Model", default=agent.get("model", ""))

        data = {
            "name": name,
            "description": description,
            "model": model,
        }

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Updating agent...", total=None)
            client.update_agent(agent_id, data)

        console.print(f"[green]✓[/green] Agent {agent_id} updated successfully")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to update agent: {str(e)}")
        raise click.Abort()


@agents.command("delete")
@click.argument("agent_id", type=int)
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_agent(agent_id: int, force: bool, api_url: str):
    """Delete an agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Get agent info for confirmation
        if not force:
            response = client.get_agent(agent_id)
            agent = response.get("data", response)

            if not Confirm.ask(f"Delete agent '{agent['name']}'?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting agent...", total=None)
            client.delete_agent(agent_id)

        console.print(f"[green]✓[/green] Agent {agent_id} deleted")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete agent: {str(e)}")
        raise click.Abort()


@agents.command("attach-tool")
@click.argument("agent_id", type=int)
@click.argument("tool_ids", type=int, nargs=-1, required=True)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def attach_tool(agent_id: int, tool_ids: tuple, api_url: str):
    """Attach tools to an agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Attaching tools...", total=None)
            client.attach_tools(agent_id, list(tool_ids))

        console.print(f"[green]✓[/green] Attached {len(tool_ids)} tool(s) to agent {agent_id}")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to attach tools: {str(e)}")
        raise click.Abort()


@agents.command("detach-tool")
@click.argument("agent_id", type=int)
@click.argument("tool_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def detach_tool(agent_id: int, tool_id: int, api_url: str):
    """Detach a tool from an agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Detaching tool...", total=None)
            client.detach_tool(agent_id, tool_id)

        console.print(f"[green]✓[/green] Detached tool {tool_id} from agent {agent_id}")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to detach tool: {str(e)}")
        raise click.Abort()
