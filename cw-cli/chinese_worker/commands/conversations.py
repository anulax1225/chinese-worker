"""Conversation management commands."""

import os
from typing import Optional
import click
from rich.console import Console
from rich.panel import Panel
from rich.prompt import Confirm
from rich.table import Table
from rich.progress import Progress, SpinnerColumn, TextColumn

from ..api import APIClient, AuthManager

console = Console()


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


@click.group()
def conversations():
    """Manage conversations."""
    pass


@conversations.command("list")
@click.option("--agent-id", type=int, help="Filter by agent ID")
@click.option("--status", type=click.Choice(["active", "completed", "failed", "cancelled"]), help="Filter by status")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_conversations(agent_id: Optional[int], status: Optional[str], api_url: str):
    """List conversations."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        conversations_list = client.list_conversations(
            agent_id=agent_id,
            status=status
        )

        if not conversations_list:
            console.print("[yellow]![/yellow] No conversations found")
            return

        table = Table(
            title=f"Conversations ({len(conversations_list)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("ID", style="cyan", width=8)
        table.add_column("Agent ID", width=10)
        table.add_column("Status", width=12)
        table.add_column("Messages", width=10)
        table.add_column("Turns", width=8)
        table.add_column("Last Activity", width=20)

        for conv in conversations_list:
            msg_count = len(conv.get("messages", []))
            last_activity = conv.get("last_activity_at", "")
            if last_activity:
                last_activity = last_activity.split(".")[0].replace("T", " ")

            status_val = conv.get("status", "unknown")
            status_style = {
                "active": "green",
                "completed": "blue",
                "failed": "red",
                "cancelled": "yellow",
            }.get(status_val, "")

            status_str = f"[{status_style}]{status_val}[/{status_style}]" if status_style else status_val

            table.add_row(
                str(conv["id"]),
                str(conv["agent_id"]),
                status_str,
                str(msg_count),
                str(conv.get("turn_count", 0)),
                last_activity
            )

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list conversations: {str(e)}")
        raise click.Abort()


@conversations.command("show")
@click.argument("conversation_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_conversation(conversation_id: int, api_url: str):
    """Show conversation details and history."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_conversation(conversation_id)
        conv = response.get("data", response)

        # Header
        status = conv.get("status", "unknown")
        status_style = {
            "active": "green",
            "completed": "blue",
            "failed": "red",
            "cancelled": "yellow",
        }.get(status, "")

        details = f"Agent ID: {conv['agent_id']}\n"
        details += f"Status: [{status_style}]{status}[/{status_style}]\n"
        details += f"Turns: {conv.get('turn_count', 0)}\n"
        details += f"Messages: {len(conv.get('messages', []))}"

        console.print(Panel(
            details,
            title=f"Conversation #{conv['id']}",
            border_style="blue"
        ))

        # Messages
        messages = conv.get("messages", [])
        if messages:
            console.print("\n[bold]Messages:[/bold]")
            for msg in messages:
                role = msg.get("role", "unknown")
                content = msg.get("content", "")

                if role == "user":
                    console.print(f"\n[cyan]You:[/cyan] {content}")
                elif role == "assistant":
                    thinking = msg.get("thinking", "")
                    if thinking:
                        console.print(f"\n[dim italic]💭 {thinking[:200]}...[/dim italic]")
                    if content:
                        console.print(f"\n[green]Assistant:[/green] {content[:500]}")
                        if len(content) > 500:
                            console.print("[dim]...(truncated)[/dim]")

                    tool_calls = msg.get("tool_calls", [])
                    if tool_calls:
                        for tc in tool_calls:
                            console.print(f"[dim]  → Tool: {tc.get('name', 'unknown')}[/dim]")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get conversation: {str(e)}")
        raise click.Abort()


@conversations.command("stop")
@click.argument("conversation_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def stop_conversation(conversation_id: int, api_url: str):
    """Stop a running conversation."""
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
            progress.add_task("Stopping conversation...", total=None)
            result = client.stop_conversation(conversation_id)

        status = result.get("status", "unknown")
        if status == "cancelled":
            console.print(f"[green]✓[/green] Conversation {conversation_id} stopped")
        else:
            console.print(f"[yellow]![/yellow] {result.get('message', 'Conversation was not running')}")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to stop conversation: {str(e)}")
        raise click.Abort()


@conversations.command("delete")
@click.argument("conversation_id", type=int)
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_conversation(conversation_id: int, force: bool, api_url: str):
    """Delete a conversation."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        if not force:
            if not Confirm.ask(f"Delete conversation {conversation_id}?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting...", total=None)
            client.delete_conversation(conversation_id)

        console.print(f"[green]✓[/green] Conversation {conversation_id} deleted")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete conversation: {str(e)}")
        raise click.Abort()
