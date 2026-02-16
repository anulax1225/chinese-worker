"""System prompt management commands."""

import os
import subprocess
import tempfile
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


def edit_in_editor(initial_content: str, suffix: str = ".txt") -> str:
    """Open content in $EDITOR and return edited content."""
    editor = os.environ.get("EDITOR", "vim")

    with tempfile.NamedTemporaryFile(mode="w", suffix=suffix, delete=False) as f:
        f.write(initial_content)
        f.flush()
        temp_path = f.name

    try:
        subprocess.run([editor, temp_path], check=True)
        with open(temp_path, "r") as f:
            return f.read()
    finally:
        os.unlink(temp_path)


@click.group()
def prompts():
    """Manage system prompts."""
    pass


@prompts.command("list")
@click.option("--active", is_flag=True, help="Show only active prompts")
@click.option("--search", help="Search query")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_prompts(active: bool, search: str, api_url: str):
    """List all system prompts."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        prompts_list = client.list_system_prompts(
            search=search,
            active=active if active else None,
        )

        if not prompts_list:
            console.print("[yellow]![/yellow] No system prompts found")
            console.print("Create one with: cw prompts create")
            return

        table = Table(
            title=f"System Prompts ({len(prompts_list)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("ID", style="cyan", width=6)
        table.add_column("Name", width=30)
        table.add_column("Active", width=8)
        table.add_column("Variables", width=15)
        table.add_column("Preview", width=30)

        for prompt in prompts_list:
            active_str = "[green]Yes[/green]" if prompt.get("is_active") else "[dim]No[/dim]"
            variables = prompt.get("variables", [])
            vars_str = ", ".join(variables[:3])
            if len(variables) > 3:
                vars_str += f" +{len(variables) - 3}"

            template = prompt.get("template", "")
            preview = template[:30].replace("\n", " ")
            if len(template) > 30:
                preview += "..."

            table.add_row(
                str(prompt["id"]),
                prompt["name"],
                active_str,
                vars_str or "-",
                preview,
            )

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list prompts: {str(e)}")
        raise click.Abort()


@prompts.command("show")
@click.argument("prompt_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_prompt(prompt_id: int, api_url: str):
    """Show system prompt details."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_system_prompt(prompt_id)
        prompt = response.get("data", response)

        # Header
        status = "[green]Active[/green]" if prompt.get("is_active") else "[dim]Inactive[/dim]"
        details = f"[bold]{prompt['name']}[/bold] ({status})\n"

        variables = prompt.get("variables", [])
        if variables:
            details += f"\nVariables: {', '.join(variables)}"

        console.print(Panel(
            details,
            title=f"System Prompt #{prompt['id']}",
            border_style="blue"
        ))

        # Template
        console.print("\n[bold]Template:[/bold]")
        console.print(Panel(
            prompt.get("template", "(empty)"),
            border_style="dim"
        ))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get prompt: {str(e)}")
        raise click.Abort()


@prompts.command("create")
@click.option("--name", prompt=True, help="Prompt name")
@click.option("--template", help="Template content (opens editor if not provided)")
@click.option("--active/--inactive", default=True, help="Set prompt as active")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def create_prompt(name: str, template: str, active: bool, api_url: str):
    """Create a new system prompt."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Open editor if template not provided
        if template is None:
            console.print("[dim]Opening editor for template content...[/dim]")
            template = edit_in_editor(
                "# Enter your system prompt template here\n"
                "# Use {{variable}} syntax for variables\n\n",
                suffix=".md"
            )

        data = {
            "name": name,
            "template": template,
            "is_active": active,
        }

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Creating prompt...", total=None)
            response = client.create_system_prompt(data)

        prompt = response.get("data", response)
        console.print(f"[green]✓[/green] Prompt created successfully (ID: {prompt.get('id', 'N/A')})")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to create prompt: {str(e)}")
        raise click.Abort()


@prompts.command("edit")
@click.argument("prompt_id", type=int)
@click.option("--name", help="New prompt name")
@click.option("--template", is_flag=True, help="Edit template in editor")
@click.option("--active/--inactive", default=None, help="Set active status")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def edit_prompt(prompt_id: int, name: str, template: bool, active: bool, api_url: str):
    """Edit a system prompt."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Get current prompt data
        response = client.get_system_prompt(prompt_id)
        prompt = response.get("data", response)

        data = {}

        # Name
        if name is not None:
            data["name"] = name
        else:
            new_name = Prompt.ask("Name", default=prompt["name"])
            if new_name != prompt["name"]:
                data["name"] = new_name

        # Template (open editor if flag set)
        if template:
            console.print("[dim]Opening editor for template...[/dim]")
            new_template = edit_in_editor(prompt.get("template", ""), suffix=".md")
            data["template"] = new_template

        # Active status
        if active is not None:
            data["is_active"] = active

        if not data:
            console.print("[yellow]![/yellow] No changes to make")
            return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Updating prompt...", total=None)
            client.update_system_prompt(prompt_id, data)

        console.print(f"[green]✓[/green] Prompt {prompt_id} updated successfully")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to update prompt: {str(e)}")
        raise click.Abort()


@prompts.command("delete")
@click.argument("prompt_id", type=int)
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_prompt(prompt_id: int, force: bool, api_url: str):
    """Delete a system prompt."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        if not force:
            response = client.get_system_prompt(prompt_id)
            prompt = response.get("data", response)

            if not Confirm.ask(f"Delete prompt '{prompt['name']}'?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting prompt...", total=None)
            client.delete_system_prompt(prompt_id)

        console.print(f"[green]✓[/green] Prompt {prompt_id} deleted")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete prompt: {str(e)}")
        raise click.Abort()
