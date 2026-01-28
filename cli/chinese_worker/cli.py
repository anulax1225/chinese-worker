"""Main CLI entry point."""

import click
import time
import os
from rich.console import Console
from rich.markdown import Markdown
from rich.panel import Panel
from rich.prompt import Prompt
from rich.table import Table
from rich.progress import Progress, SpinnerColumn, TextColumn
from typing import Optional, Dict, Any, List

from .api import APIClient, AuthManager
from .tools import BashTool, ReadTool, WriteTool, EditTool, GlobTool, GrepTool

console = Console()


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


def safe_get(data: Any, *keys, default=None) -> Any:
    """Safely get nested dictionary values."""
    for key in keys:
        if isinstance(data, dict):
            data = data.get(key, default)
        else:
            return default
    return data


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
        user_name = safe_get(data, "user", "name", default=email)
        console.print(f"Welcome, {user_name}!")

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
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
@click.option("--agent-id", type=int, help="Filter by agent ID")
@click.option("--status", help="Filter by status (active, completed, etc.)")
def conversations(api_url: str, agent_id: Optional[int], status: Optional[str]):
    """List your conversations."""
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

        table = Table(show_header=True, header_style="bold cyan", title=f"\nConversations ({len(conversations_list)})")
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
                # Format timestamp nicely
                last_activity = last_activity.split(".")[0].replace("T", " ")

            # Color status
            status_val = conv.get("status", "unknown")
            if status_val == "active":
                status_str = f"[green]{status_val}[/green]"
            elif status_val == "completed":
                status_str = f"[blue]{status_val}[/blue]"
            elif status_val == "failed":
                status_str = f"[red]{status_val}[/red]"
            else:
                status_str = status_val

            table.add_row(
                str(conv["id"]),
                str(conv["agent_id"]),
                status_str,
                str(msg_count),
                str(conv.get("turn_count", 0)),
                last_activity
            )

        console.print(table)
        console.print()

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list conversations: {str(e)}")
        raise click.Abort()


@main.command()
@click.argument("agent_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
@click.option("--poll-interval", default=2, help="Polling interval in seconds")
@click.option("--conversation-id", type=int, help="Resume existing conversation")
def chat(agent_id: int, api_url: str, poll_interval: int, conversation_id: Optional[int]):
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
        agent_response = client.get_agent(agent_id)
        agent = safe_get(agent_response, "data", default=agent_response)

        console.print(Panel(
            f"[bold]{agent['name']}[/bold]\n{agent.get('description', '')}",
            title=f"Agent {agent_id}",
            border_style="blue"
        ))

        # Handle conversation selection or creation
        if conversation_id:
            # Resume existing conversation
            try:
                conv_response = client.get_conversation(conversation_id)
                conversation = safe_get(conv_response, "data", default=conv_response)
                console.print(f"[green]✓[/green] Resumed conversation {conversation_id}\n")

                # Show conversation history
                show_conversation_history(conversation)

            except Exception as e:
                console.print(f"[red]✗[/red] Failed to resume conversation: {str(e)}")
                return
        else:
            # List existing conversations and let user choose
            conversation = select_or_create_conversation(client, agent_id, agent['name'])
            if not conversation:
                return

        conversation_id = conversation["id"]

        # Chat loop - continues until user exits
        console.print("[dim]Type 'exit', 'quit', or 'bye' to end the chat[/dim]\n")

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
                console.print()  # Add spacing
                response = client.send_message(conversation_id, user_message)

                # Handle response with polling
                result = handle_conversation_status(
                    client, conversation_id, response, tools, poll_interval
                )

                if result == "error":
                    console.print("[yellow]⚠[/yellow] Error occurred, but you can continue chatting")

                console.print()  # Add spacing before next prompt

            except KeyboardInterrupt:
                console.print("\n\n[yellow]Chat interrupted. Type 'exit' to end or continue chatting[/yellow]\n")
                continue
            except Exception as e:
                console.print(f"\n[red]✗[/red] Error: {str(e)}")
                console.print("[yellow]You can try sending another message or type 'exit' to quit[/yellow]\n")
                continue

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to start chat: {str(e)}")
        raise click.Abort()


def select_or_create_conversation(client: APIClient, agent_id: int, agent_name: str) -> Optional[Dict[str, Any]]:
    """
    Let user select an existing conversation or create a new one.

    Returns:
        Conversation dict or None if user cancels
    """
    try:
        # Get existing conversations for this agent
        conversations = client.list_conversations(agent_id=agent_id, status="active")

        if conversations:
            console.print(f"\n[bold]Existing conversations with {agent_name}:[/bold]\n")

            table = Table(show_header=True, header_style="bold cyan")
            table.add_column("#", style="cyan", width=6)
            table.add_column("ID", style="dim", width=8)
            table.add_column("Messages", width=10)
            table.add_column("Last Activity", width=20)

            for idx, conv in enumerate(conversations[:10], 1):  # Show max 10
                msg_count = len(conv.get("messages", []))
                last_activity = conv.get("last_activity_at", "")
                if last_activity:
                    # Format timestamp nicely
                    last_activity = last_activity.split(".")[0].replace("T", " ")

                table.add_row(
                    str(idx),
                    str(conv["id"]),
                    str(msg_count),
                    last_activity
                )

            console.print(table)
            console.print()

            choice = Prompt.ask(
                "[bold]Select conversation or create new[/bold]",
                choices=[str(i) for i in range(1, min(len(conversations), 10) + 1)] + ["new"],
                default="new"
            )

            if choice == "new":
                return create_new_conversation(client, agent_id)
            else:
                idx = int(choice) - 1
                selected = conversations[idx]

                # Get full conversation with history
                conv_response = client.get_conversation(selected["id"])
                conversation = safe_get(conv_response, "data", default=conv_response)

                console.print(f"[green]✓[/green] Selected conversation {conversation['id']}\n")
                show_conversation_history(conversation)

                return conversation
        else:
            # No existing conversations, create new
            console.print(f"\n[dim]No existing conversations found[/dim]\n")
            return create_new_conversation(client, agent_id)

    except Exception as e:
        console.print(f"[yellow]⚠[/yellow] Could not list conversations: {str(e)}")
        console.print("[dim]Creating new conversation...[/dim]\n")
        return create_new_conversation(client, agent_id)


def create_new_conversation(client: APIClient, agent_id: int) -> Dict[str, Any]:
    """Create a new conversation."""
    with Progress(
        SpinnerColumn(),
        TextColumn("[progress.description]{task.description}"),
        console=console,
    ) as progress:
        progress.add_task("Creating new conversation...", total=None)
        conv_response = client.create_conversation(agent_id)
        conversation = safe_get(conv_response, "data", default=conv_response)

    console.print(f"[green]✓[/green] New conversation created (ID: {conversation['id']})\n")
    return conversation


def show_conversation_history(conversation: Dict[str, Any]) -> None:
    """Display conversation history."""
    messages = conversation.get("messages", [])

    if not messages:
        return

    console.print("[bold]Conversation History:[/bold]\n")

    for message in messages:
        role = message.get("role", "unknown")
        content = message.get("content", "")

        if role == "user":
            console.print(f"[bold cyan]You:[/bold cyan] {content}")
        elif role == "assistant":
            console.print(f"[bold green]Assistant:[/bold green]")
            render_assistant_message(content)

        console.print()

    console.print("[dim]" + "─" * 60 + "[/dim]\n")


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
        Status: "completed", "error", or "active"
    """
    current_status = initial_response.get("status")

    while True:
        if current_status == "completed":
            # Get final conversation to show assistant's response
            try:
                conv_response = client.get_conversation(conversation_id)
                conversation = safe_get(conv_response, "data", default=conv_response)
                show_assistant_message(conversation)
            except Exception as e:
                console.print(f"[yellow]⚠[/yellow] Could not retrieve final response: {str(e)}")
            return "completed"

        elif current_status == "failed":
            error_msg = initial_response.get("error", "Unknown error")
            console.print(f"[red]Error:[/red] {error_msg}")
            return "error"

        elif current_status == "waiting_for_tool":
            # Execute tool and submit result
            tool_request = initial_response.get("tool_request")
            if not tool_request:
                console.print("[red]✗[/red] Missing tool request data")
                return "error"

            # Execute the tool
            tool_name = tool_request.get("name")
            tool_args = tool_request.get("arguments", {})
            call_id = tool_request.get("call_id")

            if not tool_name or not call_id:
                console.print("[red]✗[/red] Invalid tool request")
                return "error"

            console.print(f"[dim]→ Executing {tool_name}...[/dim]")

            if tool_name in tools:
                try:
                    success, output, error = tools[tool_name].execute(tool_args)

                    # Show tool output if requested
                    if output and len(output) < 500:
                        console.print(f"[dim]  Output: {output[:200]}{'...' if len(output) > 200 else ''}[/dim]")
                    elif error:
                        console.print(f"[dim]  Error: {error[:200]}{'...' if len(error) > 200 else ''}[/dim]")

                    # Submit result
                    response = client.submit_tool_result(
                        conversation_id, call_id, success, output, error
                    )
                    current_status = response.get("status")
                    initial_response = response

                except Exception as e:
                    console.print(f"[red]✗[/red] Tool execution failed: {str(e)}")
                    # Submit error result
                    try:
                        response = client.submit_tool_result(
                            conversation_id, call_id, False, None, f"Tool execution failed: {str(e)}"
                        )
                        current_status = response.get("status")
                        initial_response = response
                    except Exception:
                        return "error"
            else:
                console.print(f"[red]✗[/red] Unknown tool: {tool_name}")
                # Submit error result
                try:
                    response = client.submit_tool_result(
                        conversation_id, call_id, False, None, f"Unknown tool: {tool_name}"
                    )
                    current_status = response.get("status")
                    initial_response = response
                except Exception:
                    return "error"

        elif current_status == "processing":
            # Poll for status updates
            time.sleep(poll_interval)

            try:
                response = client.get_status(conversation_id)
                current_status = response.get("status")
                initial_response = response
            except Exception as e:
                console.print(f"[yellow]⚠[/yellow] Polling error: {str(e)}")
                return "error"

        else:
            console.print(f"[yellow]![/yellow] Unknown status: {current_status}")
            return current_status


def show_assistant_message(conversation: Dict[str, Any]) -> None:
    """Display the last assistant message from the conversation."""
    messages = conversation.get("messages", [])

    # Find the last assistant message
    for message in reversed(messages):
        if message.get("role") == "assistant":
            content = message.get("content", "")

            console.print("[bold green]Assistant:[/bold green]")
            render_assistant_message(content)
            break


def render_assistant_message(content: str) -> None:
    """Render assistant message with markdown support."""
    if not content:
        console.print("[dim]<empty response>[/dim]")
        return

    # Try to render as markdown if it looks like markdown
    if any(marker in content for marker in ["```", "##", "**", "*", "`", "- ", "1. "]):
        try:
            md = Markdown(content)
            console.print(md)
        except Exception:
            console.print(content)
    else:
        console.print(content)


if __name__ == "__main__":
    main()
