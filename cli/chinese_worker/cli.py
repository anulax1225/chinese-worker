"""Main CLI entry point."""

import os
import platform
import time
from pathlib import Path
from typing import Any, Dict, List, Optional

import click
from prompt_toolkit import PromptSession
from prompt_toolkit.history import FileHistory
from rich.console import Console, Group
from rich.live import Live
from rich.markdown import Markdown
from rich.panel import Panel
from rich.progress import Progress, SpinnerColumn, TextColumn
from rich.prompt import Prompt
from rich.table import Table
from rich.text import Text

from .api import APIClient, AuthManager, SSEClient, SSEEventHandler
from .tools import (
    # Shell tools
    BashTool,
    PowerShellTool,
    # File tools
    EditTool,
    GlobTool,
    GrepTool,
    ReadTool,
    WriteTool,
    # Cross-platform tools
    ClipboardTool,
    NotifyTool,
    OpenTool,
    SysInfoTool,
    # OS-specific tools
    AppleScriptTool,
    RegistryTool,
    SystemctlTool,
)
from .tools.base import BaseTool

console = Console()


def get_config_dir() -> Path:
    """Get platform-appropriate config directory."""
    system = platform.system().lower()
    if system == "windows":
        base = Path(os.getenv("APPDATA", os.path.expanduser("~")))
        return base / "chinese-worker"
    elif system == "darwin":
        return Path.home() / "Library" / "Application Support" / "chinese-worker"
    else:
        return Path.home() / ".cw"


def get_history_file() -> Path:
    """Get platform-appropriate history file path, ensuring directory exists."""
    config_dir = get_config_dir()
    config_dir.mkdir(parents=True, exist_ok=True)
    return config_dir / "history"


# Input history file path (for backward compatibility)
HISTORY_FILE = str(get_history_file())


def get_platform_tools() -> Dict[str, BaseTool]:
    """Get tools appropriate for the current platform."""
    # File tools available on all platforms
    tools: Dict[str, BaseTool] = {
        "read": ReadTool(),
        "write": WriteTool(),
        "edit": EditTool(),
        "glob": GlobTool(),
        "grep": GrepTool(),
    }

    # Cross-platform tools (all platforms)
    tools["clipboard"] = ClipboardTool()
    tools["sysinfo"] = SysInfoTool()
    tools["open"] = OpenTool()
    tools["notify"] = NotifyTool()

    # Platform-specific tools
    system = platform.system().lower()
    if system == "windows":
        tools["powershell"] = PowerShellTool()
        tools["registry"] = RegistryTool()
    elif system == "darwin":
        tools["bash"] = BashTool()
        tools["applescript"] = AppleScriptTool()
    else:  # Linux
        tools["bash"] = BashTool()
        tools["systemctl"] = SystemctlTool()

    return tools


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


def get_client_type() -> str:
    """Get the client type based on the operating system."""
    system = platform.system().lower()
    if system == "linux":
        return "cli_linux"
    elif system == "darwin":
        return "cli_macos"
    elif system == "windows":
        return "cli_windows"
    else:
        return f"cli_{system}"


def get_tool_schemas(tools: Dict[str, BaseTool]) -> List[Dict[str, Any]]:
    """Get schemas for all tools to send to server."""
    return [tool.get_schema() for tool in tools.values()]


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
            elif status_val == "cancelled":
                status_str = f"[yellow]{status_val}[/yellow]"
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


@main.command("stop")
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


@main.command("delete")
@click.argument("conversation_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
def delete_conversation_cmd(conversation_id: int, api_url: str, force: bool):
    """Delete a conversation."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    if not force:
        confirm = Prompt.ask(
            f"[yellow]Delete conversation {conversation_id}?[/yellow]",
            choices=["y", "n"],
            default="n"
        )
        if confirm != "y":
            console.print("[dim]Cancelled[/dim]")
            return

    try:
        client.delete_conversation(conversation_id)
        console.print(f"[green]✓[/green] Conversation {conversation_id} deleted")
    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete conversation: {str(e)}")
        raise click.Abort()


@main.command()
@click.argument("agent_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
@click.option("--poll-interval", default=2, help="Polling interval in seconds")
@click.option("--conversation-id", type=int, help="Resume existing conversation")
@click.option("--polling", is_flag=True, help="Force polling mode instead of SSE")
def chat(agent_id: int, api_url: str, poll_interval: int, conversation_id: Optional[int], polling: bool):
    """Start a chat session with an agent."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    # Initialize platform-specific tools
    tools = get_platform_tools()

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
            conversation = select_or_create_conversation(client, agent_id, agent['name'], tools)
            if not conversation:
                return

        conversation_id = conversation["id"]

        # Check if conversation has a pending tool request
        pending_result = handle_pending_tool_request(client, conversation_id, conversation, tools, poll_interval, polling)
        if pending_result == "error":
            console.print("[yellow]⚠[/yellow] Error handling pending tool request")

        # Chat loop - continues until user exits
        console.print("[dim]Type 'exit', 'quit', or 'bye' to end the chat[/dim]\n")

        # Initialize prompt session with history
        session = PromptSession(history=FileHistory(HISTORY_FILE))

        while True:
            try:
                # Get user input with history (UP/DOWN) and cursor movement (LEFT/RIGHT)
                user_message = session.prompt("You: ")

                if not user_message.strip():
                    continue

                if user_message.lower() in ["exit", "quit", "bye"]:
                    console.print("\n[yellow]Ending conversation...[/yellow]")
                    break

                # Send message
                console.print()  # Add spacing
                response = client.send_message(conversation_id, user_message)

                # Handle response
                result = handle_conversation_status(
                    client, conversation_id, response, tools, poll_interval, polling
                )

                if result == "error":
                    console.print("[yellow]⚠[/yellow] Error occurred, but you can continue chatting")

                console.print()  # Add spacing before next prompt

            except KeyboardInterrupt:
                # Ask if user wants to stop the conversation
                console.print()
                choice = Prompt.ask(
                    "\n[yellow]Interrupt received. Stop conversation?[/yellow]",
                    choices=["y", "n"],
                    default="n"
                )
                if choice == "y":
                    try:
                        client.stop_conversation(conversation_id)
                        console.print("[yellow]Conversation stopped[/yellow]")
                    except Exception:
                        pass  # Ignore errors - conversation may already be stopped
                console.print("[dim]Type 'exit' to end chat or continue chatting[/dim]\n")
                continue
            except EOFError:
                # Ctrl+D pressed
                console.print("\n[yellow]Ending conversation...[/yellow]")
                break
            except Exception as e:
                console.print(f"\n[red]✗[/red] Error: {str(e)}")
                console.print("[yellow]You can try sending another message or type 'exit' to quit[/yellow]\n")
                continue

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to start chat: {str(e)}")
        raise click.Abort()


def select_or_create_conversation(
    client: APIClient,
    agent_id: int,
    agent_name: str,
    tools: Optional[Dict[str, BaseTool]] = None,
) -> Optional[Dict[str, Any]]:
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
                return create_new_conversation(client, agent_id, tools)
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
            return create_new_conversation(client, agent_id, tools)

    except Exception as e:
        console.print(f"[yellow]⚠[/yellow] Could not list conversations: {str(e)}")
        console.print("[dim]Creating new conversation...[/dim]\n")
        return create_new_conversation(client, agent_id, tools)


def create_new_conversation(
    client: APIClient, agent_id: int, tools: Optional[Dict[str, BaseTool]] = None
) -> Dict[str, Any]:
    """Create a new conversation with client tool schemas."""
    # Get client info
    client_type = get_client_type()
    client_tool_schemas = get_tool_schemas(tools) if tools else None

    with Progress(
        SpinnerColumn(),
        TextColumn("[progress.description]{task.description}"),
        console=console,
    ) as progress:
        progress.add_task("Creating new conversation...", total=None)
        conv_response = client.create_conversation(
            agent_id,
            client_type=client_type,
            client_tool_schemas=client_tool_schemas,
        )
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
        thinking = message.get("thinking", "")
        tool_calls = message.get("tool_calls", [])

        if role == "user":
            console.print(f"[bold cyan]You:[/bold cyan] {content}")
        elif role == "assistant":
            # Show thinking if present (separate from content)
            if thinking:
                console.print(f"[dim italic]💭 {thinking}[/dim italic]")

            # Show content (actual response) if present
            if content:
                console.print(f"[bold green]Assistant:[/bold green]")
                render_assistant_message(content)

            # Show tool calls if present
            if tool_calls:
                for tc in tool_calls:
                    tool_name = tc.get("name", "unknown")
                    tool_args = tc.get("arguments", {})
                    console.print(f"[dim]  → Used tool: {tool_name}[/dim]")
                    # Show brief args preview
                    if tool_name == "bash":
                        console.print(f"[dim]    $ {tool_args.get('command', '')[:60]}[/dim]")
                    elif tool_name in ["read", "write", "edit"]:
                        console.print(f"[dim]    file: {tool_args.get('file_path', '')}[/dim]")
                    elif tool_name in ["glob", "grep"]:
                        console.print(f"[dim]    pattern: {tool_args.get('pattern', '')}[/dim]")
            elif not content and not thinking:
                # No content, no thinking, and no tool calls - truly empty
                console.print("[dim]  (processing...)[/dim]")
        elif role == "tool":
            # Show tool results briefly
            tool_output = content[:100] + ('...' if len(content) > 100 else '') if content else "(no output)"
            console.print(f"[dim]  ← Result: {tool_output}[/dim]")

        console.print()

    console.print("[dim]" + "─" * 60 + "[/dim]\n")


def handle_pending_tool_request(
    client: APIClient,
    conversation_id: int,
    conversation: Dict[str, Any],
    tools: Dict[str, Any],
    poll_interval: int,
    force_polling: bool = False,
) -> str:
    """
    Check if conversation has a pending tool request and handle it.

    Returns:
        Status: "completed", "continue", or "error"
    """
    # Check if conversation is waiting for a tool (server-side state)
    status = conversation.get("status")
    waiting_for = conversation.get("waiting_for")
    pending_tool = conversation.get("pending_tool_request")

    if status == "paused" and waiting_for == "tool_result" and pending_tool:
        console.print("[yellow]![/yellow] Found pending tool request from previous session\n")

        # Create a response dict that matches what handle_conversation_status expects
        response = {
            "status": "waiting_for_tool",
            "tool_request": pending_tool,
            "conversation_id": conversation_id,
        }

        return handle_conversation_status(client, conversation_id, response, tools, poll_interval, force_polling)

    # Also check message history for unanswered tool calls
    messages = conversation.get("messages", [])
    if messages:
        last_msg = messages[-1]
        if last_msg.get("role") == "assistant":
            tool_calls = last_msg.get("tool_calls", [])
            if tool_calls:
                # Check if there's a tool result for each tool call
                # Look for tool messages after the assistant message
                answered_call_ids = set()
                for msg in messages:
                    if msg.get("role") == "tool" and msg.get("tool_call_id"):
                        answered_call_ids.add(msg["tool_call_id"])

                # Find unanswered tool calls
                for tc in tool_calls:
                    call_id = tc.get("call_id") or tc.get("id")
                    if call_id and call_id not in answered_call_ids:
                        console.print("[yellow]![/yellow] Found unanswered tool call from previous session\n")

                        # Create tool request from tool call
                        tool_request = {
                            "call_id": call_id,
                            "name": tc.get("name"),
                            "arguments": tc.get("arguments", {}),
                        }

                        response = {
                            "status": "waiting_for_tool",
                            "tool_request": tool_request,
                            "conversation_id": conversation_id,
                        }

                        return handle_conversation_status(client, conversation_id, response, tools, poll_interval, force_polling)

    return "continue"


def handle_conversation_status(
    client: APIClient,
    conversation_id: int,
    initial_response: Dict[str, Any],
    tools: Dict[str, Any],
    poll_interval: int,
    force_polling: bool = False,
) -> str:
    """
    Handle conversation status with SSE (preferred) or polling fallback.

    Returns:
        Status: "completed", "error", or "active"
    """
    # Use polling if forced
    if force_polling:
        return handle_polling_status(client, conversation_id, initial_response, tools, poll_interval)

    # Try SSE first for real-time updates
    try:
        return handle_sse_events(client, conversation_id, initial_response, tools)
    except Exception as e:
        console.print(f"[dim]SSE unavailable ({str(e)[:30]}), using polling...[/dim]")

    # Fall back to polling
    return handle_polling_status(client, conversation_id, initial_response, tools, poll_interval)


def handle_sse_events(
    client: APIClient,
    conversation_id: int,
    initial_response: Dict[str, Any],
    tools: Dict[str, Any],
) -> str:
    """
    Handle conversation status via SSE stream.

    Returns:
        Status: "completed", "error", or "active"
    """
    auto_approve = False
    last_shown_message_index = -1

    # Check if already in a terminal or waiting state
    current_status = initial_response.get("status")
    if current_status == "completed":
        return handle_completed_status(client, conversation_id, last_shown_message_index)
    elif current_status == "failed":
        console.print(f"[red]Error:[/red] {initial_response.get('error', 'Unknown error')}")
        return "error"
    elif current_status == "waiting_for_tool":
        # Handle immediate tool request
        result, auto_approve, last_shown_message_index = handle_tool_request_status(
            client, conversation_id, initial_response, tools, auto_approve, last_shown_message_index
        )
        if result == "continue":
            pass  # Continue to SSE loop
        else:
            return result

    # SSE loop - reconnect after tool requests since server closes connection
    while True:
        # Create SSE client
        sse_client = SSEClient(
            base_url=client.base_url,
            conversation_id=conversation_id,
            headers=client._get_headers(),
            timeout=120,  # 2 minute read timeout
        )

        accumulated_content = ""
        accumulated_thinking = ""
        pending_tool_request = None
        final_event = None
        final_stats = None
        error_msg = None
        current_tool = None

        try:
            # PHASE 1: Live display for streaming ONLY - no user interaction here
            with Live(console=console, refresh_per_second=10, transient=True) as live:
                for event_type, data in sse_client.events():
                    if event_type == "connected":
                        continue

                    elif event_type == "text_chunk":
                        # Progressive text rendering with markdown
                        chunk = data.get("chunk", "")
                        chunk_type = data.get("type", "content")
                        if chunk_type == "thinking":
                            accumulated_thinking += chunk
                        else:
                            accumulated_content += chunk

                        # Build and update the live display
                        live.update(build_streaming_display(accumulated_thinking, accumulated_content, current_tool))

                    elif event_type == "status_changed":
                        # Status update (e.g., processing)
                        pass

                    elif event_type == "tool_executing":
                        # Server-side tool started (web_search, web_fetch, etc.)
                        current_tool = data.get("tool", {})
                        live.update(build_streaming_display(accumulated_thinking, accumulated_content, current_tool))

                    elif event_type == "tool_completed":
                        # Server-side tool finished
                        current_tool = None
                        live.update(build_streaming_display(accumulated_thinking, accumulated_content, None))

                    elif event_type == "tool_request":
                        # Store tool request, handle AFTER Live context exits
                        pending_tool_request = data.get("tool_request")
                        final_event = "tool_request"
                        break  # Exit Live context first!

                    elif event_type == "completed":
                        final_event = "completed"
                        final_stats = data.get("stats")
                        break

                    elif event_type == "failed":
                        final_event = "failed"
                        error_msg = data.get("error", "Unknown error")
                        final_stats = data.get("stats")
                        break

                    elif event_type == "cancelled":
                        final_event = "cancelled"
                        final_stats = data.get("stats")
                        break
        finally:
            # Always close SSE connection to release resources
            sse_client.close()

        # PHASE 2: After Live context exits - print final content ONCE
        if accumulated_content or accumulated_thinking:
            print_final_streaming_content(accumulated_thinking, accumulated_content)

        # PHASE 3: Handle events with clean terminal (prompts work now!)
        if final_event == "tool_request" and pending_tool_request:
            # Handle the tool request - prompts will work correctly now
            result, auto_approve, last_shown_message_index = execute_tool_request(
                client, conversation_id, pending_tool_request, tools, auto_approve, last_shown_message_index
            )

            if result == "error":
                return "error"
            elif result == "completed":
                return "completed"

            # Small delay to let server process tool result before reconnecting
            time.sleep(0.3)

            # Continue loop to reconnect SSE
            continue

        elif final_event == "completed":
            # Show stats if available
            if final_stats:
                turns = final_stats.get("turns", 0)
                tokens = final_stats.get("tokens", 0)
                if turns or tokens:
                    console.print(f"\n[dim]Completed: {turns} turns, {tokens} tokens[/dim]")
            return "completed"

        elif final_event == "failed":
            console.print(f"\n[red]Error:[/red] {error_msg}")
            return "error"

        elif final_event == "cancelled":
            console.print("\n[yellow]Conversation cancelled[/yellow]")
            if final_stats:
                turns = final_stats.get("turns", 0)
                tokens = final_stats.get("tokens", 0)
                if turns or tokens:
                    console.print(f"[dim]{turns} turns, {tokens} tokens[/dim]")
            return "cancelled"

        # No event received (connection closed without event), break
        break

    return "completed"


def build_streaming_display(thinking: str, content: str, current_tool: Optional[Dict[str, Any]] = None) -> Group:
    """Build a Rich renderable for streaming display."""
    parts = []

    if thinking:
        thinking_text = Text("💭 ", style="dim italic")
        thinking_text.append(thinking, style="dim italic")
        parts.append(thinking_text)

    if content:
        header = Text("Assistant:", style="bold green")
        parts.append(header)
        # Only render markdown if blocks are complete (avoid partial rendering issues)
        if has_complete_markdown(content):
            parts.append(Markdown(content))
        else:
            parts.append(Text(content))

    if current_tool:
        tool_name = current_tool.get("name", "unknown")
        args = current_tool.get("arguments", {})

        if tool_name == "web_search":
            query = args.get("query", "...")
            tool_panel = Panel(
                Text(f"🔍 {query}", style="cyan"),
                title="[bold cyan]Web Search[/bold cyan]",
                border_style="cyan",
                padding=(0, 1),
            )
            parts.append(tool_panel)
        elif tool_name == "web_fetch":
            url = args.get("url", "...")
            prompt = args.get("prompt", "")
            fetch_content = Text()
            fetch_content.append("🌐 ", style="blue")
            fetch_content.append(url, style="blue underline")
            if prompt:
                fetch_content.append(f"\n📝 {prompt[:60]}{'...' if len(prompt) > 60 else ''}", style="dim")
            tool_panel = Panel(
                fetch_content,
                title="[bold blue]Web Fetch[/bold blue]",
                border_style="blue",
                padding=(0, 1),
            )
            parts.append(tool_panel)
        else:
            # Default display for other tools
            tool_text = Text(f"  → Running {tool_name}...", style="dim cyan")
            parts.append(tool_text)

    if not parts:
        return Group(Text("..."))

    return Group(*parts)


def has_complete_markdown(content: str) -> bool:
    """Check if markdown code blocks are complete."""
    # Don't render partial code blocks - wait for closing ```
    if "```" in content:
        return content.count("```") % 2 == 0
    return True


def print_final_streaming_content(thinking: str, content: str) -> None:
    """Print the final streamed content with proper formatting."""
    if thinking:
        console.print(f"[dim italic]💭 {thinking}[/dim italic]")

    if content:
        console.print("[bold green]Assistant:[/bold green]")
        console.print(Markdown(content))


def handle_polling_status(
    client: APIClient,
    conversation_id: int,
    initial_response: Dict[str, Any],
    tools: Dict[str, Any],
    poll_interval: int,
) -> str:
    """
    Handle conversation status with polling for tool requests (fallback mode).

    Returns:
        Status: "completed", "error", or "active"
    """
    current_status = initial_response.get("status")
    auto_approve = False  # Track "accept all" mode
    last_shown_message_index = -1  # Track which messages we've shown

    while True:
        if current_status == "completed":
            return handle_completed_status(client, conversation_id, last_shown_message_index)

        elif current_status == "failed":
            error_msg = initial_response.get("error", "Unknown error")
            console.print(f"[red]Error:[/red] {error_msg}")
            return "error"

        elif current_status == "waiting_for_tool":
            result, auto_approve, last_shown_message_index = handle_tool_request_status(
                client, conversation_id, initial_response, tools, auto_approve, last_shown_message_index
            )
            if result == "continue":
                # Get updated status
                try:
                    response = client.get_status(conversation_id)
                    current_status = response.get("status")
                    initial_response = response
                except Exception:
                    return "error"
            else:
                return result

        elif current_status == "processing":
            # Poll for status updates with spinner
            with Progress(
                SpinnerColumn(),
                TextColumn("[progress.description]{task.description}"),
                console=console,
                transient=True,  # Remove spinner when done
            ) as progress:
                task = progress.add_task("Thinking...", total=None)

                while current_status == "processing":
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


def handle_completed_status(
    client: APIClient,
    conversation_id: int,
    last_shown_message_index: int,
) -> str:
    """Handle completed conversation status."""
    try:
        conv_response = client.get_conversation(conversation_id)
        conversation = safe_get(conv_response, "data", default=conv_response)
        show_assistant_message(conversation, last_shown_message_index)
    except Exception as e:
        console.print(f"[yellow]⚠[/yellow] Could not retrieve final response: {str(e)}")
    return "completed"


def handle_tool_request_status(
    client: APIClient,
    conversation_id: int,
    response: Dict[str, Any],
    tools: Dict[str, Any],
    auto_approve: bool,
    last_shown_message_index: int,
) -> tuple:
    """
    Handle a tool request from the server.

    Returns:
        Tuple of (result_status, updated_auto_approve, updated_last_shown_index)
        result_status is "continue" to keep looping, or a terminal status
    """
    # First, show the AI's thinking/reasoning
    try:
        conv_response = client.get_conversation(conversation_id)
        conversation = safe_get(conv_response, "data", default=conv_response)
        last_shown_message_index = show_thinking(conversation, last_shown_message_index)
    except Exception:
        pass  # Continue even if we can't show thinking

    # Execute tool and submit result
    tool_request = response.get("tool_request")
    result, auto_approve, last_shown_message_index = execute_tool_request(
        client, conversation_id, tool_request, tools, auto_approve, last_shown_message_index
    )

    return (result, auto_approve, last_shown_message_index)


def execute_tool_request(
    client: APIClient,
    conversation_id: int,
    tool_request: Optional[Dict[str, Any]],
    tools: Dict[str, Any],
    auto_approve: bool,
    last_shown_message_index: int,
) -> tuple:
    """
    Execute a tool request and submit the result.

    Returns:
        Tuple of (result_status, updated_auto_approve, updated_last_shown_index)
    """
    if not tool_request:
        console.print("[red]✗[/red] Missing tool request data")
        return ("error", auto_approve, last_shown_message_index)

    tool_name = tool_request.get("name")
    tool_args = tool_request.get("arguments", {})
    call_id = tool_request.get("call_id")

    if not tool_name or not call_id:
        console.print("[red]✗[/red] Invalid tool request")
        return ("error", auto_approve, last_shown_message_index)

    # Show tool request and ask for approval
    console.print(f"\n[bold cyan]Tool Request:[/bold cyan] {tool_name}")
    show_tool_args(tool_name, tool_args)

    if tool_name in tools:
        # Ask for user approval (unless auto_approve is on)
        if not auto_approve:
            approval = ask_tool_approval(tool_name, tool_args)
            if approval == "no":
                # Skip this tool, submit rejection
                console.print("[yellow]⊘[/yellow] Tool execution skipped by user")
                try:
                    client.submit_tool_result(
                        conversation_id, call_id, False, None, "[User refused tool execution]"
                    )
                except Exception:
                    return ("error", auto_approve, last_shown_message_index)
                return ("continue", auto_approve, last_shown_message_index)
            elif approval == "all":
                auto_approve = True

        # Execute the tool
        console.print(f"[dim]→ Executing {tool_name}...[/dim]")
        try:
            success, output, error = tools[tool_name].execute(tool_args)

            # Show tool output
            if output:
                preview = output[:300] + ('...' if len(output) > 300 else '')
                console.print(f"[dim]  Output: {preview}[/dim]")
            if error:
                console.print(f"[red]  Error: {error[:200]}[/red]")

            # Submit result - wrap error in [Tool failed: ...] format if failed
            formatted_error = f"[Tool failed: {error}]" if not success and error else error
            client.submit_tool_result(conversation_id, call_id, success, output, formatted_error)

        except Exception as e:
            console.print(f"[red]✗[/red] Tool execution failed: {str(e)}")
            # Submit error result
            try:
                client.submit_tool_result(
                    conversation_id, call_id, False, None, f"[Tool failed: {str(e)}]"
                )
            except Exception:
                return ("error", auto_approve, last_shown_message_index)
    else:
        console.print(f"[red]✗[/red] Unknown tool: {tool_name}")
        # Submit error result
        try:
            client.submit_tool_result(
                conversation_id, call_id, False, None, f"[Tool failed: Unknown tool '{tool_name}']"
            )
        except Exception:
            return ("error", auto_approve, last_shown_message_index)

    return ("continue", auto_approve, last_shown_message_index)


def show_thinking(conversation: Dict[str, Any], last_shown_index: int) -> int:
    """
    Show the AI's thinking/reasoning from new assistant messages.
    Returns the new last shown message index.
    """
    messages = conversation.get("messages", [])

    for i, message in enumerate(messages):
        if i <= last_shown_index:
            continue

        role = message.get("role", "")
        content = message.get("content", "")
        thinking = message.get("thinking", "")
        tool_calls = message.get("tool_calls", [])

        if role == "assistant":
            # Show thinking in a subtle format (separate from content)
            if thinking:
                console.print(f"\n[dim italic]💭 {thinking}[/dim italic]")

            # Show content if present (this is the actual response)
            if content:
                console.print(f"\n[bold green]Assistant:[/bold green]")
                render_assistant_message(content)

            # Show planned tool calls if there are multiple
            if tool_calls and len(tool_calls) > 1:
                console.print(f"[dim]   Planning to execute {len(tool_calls)} tools...[/dim]")

    return len(messages) - 1


def show_tool_args(tool_name: str, args: Dict[str, Any]) -> None:
    """Display tool arguments in a readable format."""
    if tool_name == "bash":
        command = args.get("command", "")
        console.print(f"[yellow]  $ {command}[/yellow]")
    elif tool_name == "read":
        console.print(f"[dim]  file: {args.get('file_path', '')}[/dim]")
    elif tool_name == "write":
        console.print(f"[dim]  file: {args.get('file_path', '')}[/dim]")
        content = args.get("content", "")
        preview = content[:100] + ('...' if len(content) > 100 else '')
        console.print(f"[dim]  content: {preview}[/dim]")
    elif tool_name == "edit":
        console.print(f"[dim]  file: {args.get('file_path', '')}[/dim]")
        console.print(f"[dim]  old: {args.get('old_string', '')[:50]}...[/dim]")
        console.print(f"[dim]  new: {args.get('new_string', '')[:50]}...[/dim]")
    elif tool_name == "glob":
        console.print(f"[dim]  pattern: {args.get('pattern', '')}[/dim]")
    elif tool_name == "grep":
        console.print(f"[dim]  pattern: {args.get('pattern', '')}[/dim]")
        if args.get("path"):
            console.print(f"[dim]  path: {args.get('path')}[/dim]")
    else:
        # Generic display for other tools
        for key, value in args.items():
            console.print(f"[dim]  {key}: {str(value)[:100]}[/dim]")


def ask_tool_approval(tool_name: str, args: Dict[str, Any]) -> str:
    """
    Ask user for approval before executing a tool.

    Returns:
        "yes" - execute this tool
        "no" - skip this tool
        "all" - execute all tools without asking
    """
    choice = Prompt.ask(
        "\n[bold]Execute this tool?[/bold]",
        choices=["y", "n", "a"],
        default="y"
    )

    if choice == "y":
        return "yes"
    elif choice == "n":
        return "no"
    elif choice == "a":
        console.print("[green]✓[/green] Auto-approving all future tool executions")
        return "all"
    return "yes"


def show_assistant_message(conversation: Dict[str, Any], last_shown_index: int = -1) -> None:
    """Display new assistant messages from the conversation."""
    messages = conversation.get("messages", [])

    # Show any new assistant messages we haven't displayed yet
    for i, message in enumerate(messages):
        if i <= last_shown_index:
            continue

        if message.get("role") == "assistant":
            content = message.get("content", "")
            thinking = message.get("thinking", "")
            tool_calls = message.get("tool_calls", [])

            # Show thinking first if present
            if thinking:
                console.print(f"\n[dim italic]💭 {thinking}[/dim italic]")

            # Show content if present
            if content:
                console.print("\n[bold green]Assistant:[/bold green]")
                render_assistant_message(content)


def render_assistant_message(content: str) -> None:
    """Render assistant message with markdown support."""
    if not content:
        return  # Don't print anything for empty content

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
