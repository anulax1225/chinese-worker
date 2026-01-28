"""Main CLI entry point."""

import click
import time
import os
from pathlib import Path
from rich.console import Console
from rich.markdown import Markdown
from rich.panel import Panel
from rich.progress import Progress, SpinnerColumn, TextColumn
from typing import Optional, Dict, Any

from .api import APIClient, AuthManager
from .tools import BashTool, ReadTool, WriteTool, EditTool, GlobTool, GrepTool

console = Console()


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


@click.group()
@click.version_option()
def main():
    """Chinese Worker CLI - AI agent execution platform."""
    pass


@main.command()
@click.option("--email", prompt=True, help="Your email address")
@click.option("--password", prompt=True, hide_input=True, help="Your password")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def login(email: str, password: str, api_url: str):
    """Authenticate with the Chinese Worker API."""
    client = APIClient(api_url)

    try:
        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Logging in...", total=None)
            data = client.login(email, password)

        console.print("[green]✓[/green] Successfully logged in!")
        console.print(f"Welcome, {data.get('user', {}).get('name', email)}!")

    except Exception as e:
        console.print(f"[red]✗[/red] Login failed: {str(e)}")
        raise click.Abort()


@main.command()
def logout():
    """Log out and remove stored credentials."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        return

    client = APIClient(get_default_api_url())
    client.logout()
    console.print("[green]✓[/green] Successfully logged out")


@main.command()
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def whoami(api_url: str):
    """Show current authenticated user."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        user = client.get_user()
        console.print(Panel(
            f"[bold]{user['name']}[/bold]\n"
            f"Email: {user['email']}\n"
            f"ID: {user['id']}",
            title="Current User",
            border_style="blue"
        ))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get user info: {str(e)}")
        raise click.Abort()


@main.command()
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def agents(api_url: str):
    """List your agents."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        agents_list = client.list_agents()

        if not agents_list:
            console.print("[yellow]![/yellow] You don't have any agents yet")
            return

        console.print(f"\n[bold]Your Agents ({len(agents_list)}):[/bold]\n")

        for agent in agents_list:
            console.print(
                f"  [cyan]{agent['id']}[/cyan] - {agent['name']} "
                f"[dim]({agent['ai_backend']})[/dim]"
            )
            if agent.get("description"):
                console.print(f"      {agent['description']}")
            console.print()

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list agents: {str(e)}")
        raise click.Abort()


@main.command()
@click.argument("agent_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
@click.option("--poll-interval", default=2, help="Polling interval in seconds")
def chat(agent_id: int, api_url: str, poll_interval: int):
    """Start a chat session with an agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    # Initialize tools
    tools = {
        "bash": BashTool(),
        "read": ReadTool(),
        "write": WriteTool(),
        "edit": EditTool(),
        "glob": GlobTool(),
        "grep": GrepTool(),
    }

    try:
        # Get agent info
        agent = client.get_agent(agent_id)
        console.print(Panel(
            f"[bold]{agent['name']}[/bold]\n{agent.get('description', '')}",
            title=f"Agent {agent_id}",
            border_style="blue"
        ))

        # Create conversation
        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Starting conversation...", total=None)
            conversation = client.create_conversation(agent_id)

        conversation_id = conversation["id"]
        console.print(f"[green]✓[/green] Conversation started (ID: {conversation_id})\n")

        # Chat loop
        while True:
            try:
                # Get user input
                user_message = console.input("[bold cyan]You:[/bold cyan] ")

                if not user_message.strip():
                    continue

                if user_message.lower() in ["exit", "quit", "bye"]:
                    console.print("\n[yellow]Ending conversation...[/yellow]")
                    break

                # Send message
                response = client.send_message(conversation_id, user_message)

                # Handle response with polling
                status = handle_conversation_status(
                    client, conversation_id, response, tools, poll_interval
                )

                if status == "failed":
                    console.print("[red]✗[/red] Conversation failed")
                    break
                elif status == "completed":
                    console.print("\n[green]Conversation completed by agent[/green]")
                    break

            except KeyboardInterrupt:
                console.print("\n\n[yellow]Chat interrupted by user[/yellow]")
                break
            except Exception as e:
                console.print(f"\n[red]✗[/red] Error: {str(e)}")
                break

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to start chat: {str(e)}")
        raise click.Abort()


def handle_conversation_status(
    client: APIClient,
    conversation_id: int,
    initial_response: Dict[str, Any],
    tools: Dict[str, Any],
    poll_interval: int,
) -> str:
    """
    Handle conversation status with polling for tool requests.

    Returns:
        Final status: "completed", "failed", or "active"
    """
    current_status = initial_response.get("status")

    while True:
        if current_status == "completed":
            # Get final conversation to show assistant's response
            conv = client.get_conversation(conversation_id)
            show_assistant_message(conv)
            return "completed"

        elif current_status == "failed":
            error_msg = initial_response.get("error", "Unknown error")
            console.print(f"\n[red]Error:[/red] {error_msg}")
            return "failed"

        elif current_status == "waiting_for_tool":
            # Execute tool and submit result
            tool_request = initial_response.get("tool_request")
            if not tool_request:
                console.print("[red]✗[/red] Missing tool request data")
                return "failed"

            # Execute the tool
            tool_name = tool_request["name"]
            tool_args = tool_request["arguments"]
            call_id = tool_request["call_id"]

            console.print(f"\n[dim]→ Executing {tool_name}...[/dim]")

            if tool_name in tools:
                success, output, error = tools[tool_name].execute(tool_args)

                # Submit result
                response = client.submit_tool_result(
                    conversation_id, call_id, success, output, error
                )
                current_status = response.get("status")
                initial_response = response
            else:
                console.print(f"[red]✗[/red] Unknown tool: {tool_name}")
                return "failed"

        elif current_status == "processing":
            # Poll for status updates
            time.sleep(poll_interval)

            with Progress(
                SpinnerColumn(),
                TextColumn("[progress.description]{task.description}"),
                console=console,
                transient=True,
            ) as progress:
                progress.add_task("Waiting for response...", total=None)
                response = client.get_status(conversation_id)

            current_status = response.get("status")
            initial_response = response

        else:
            console.print(f"[yellow]![/yellow] Unknown status: {current_status}")
            return current_status


def show_assistant_message(conversation: Dict[str, Any]):
    """Display the last assistant message from the conversation."""
    messages = conversation.get("messages", [])

    # Find the last assistant message
    for message in reversed(messages):
        if message.get("role") == "assistant":
            content = message.get("content", "")

            console.print("\n[bold green]Assistant:[/bold green]")

            # Try to render as markdown if it looks like markdown
            if any(marker in content for marker in ["```", "##", "**", "*", "`"]):
                try:
                    md = Markdown(content)
                    console.print(md)
                except Exception:
                    console.print(content)
            else:
                console.print(content)

            console.print()  # Empty line after message
            break


if __name__ == "__main__":
    main()
