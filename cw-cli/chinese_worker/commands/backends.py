"""AI backend management commands."""

import os
import click
from rich.console import Console
from rich.panel import Panel
from rich.prompt import Confirm
from rich.table import Table
from rich.progress import Progress, SpinnerColumn, TextColumn, BarColumn, TaskProgressColumn

from ..api import APIClient, AuthManager, ModelPullSSEClient

console = Console()


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


def format_size(size_bytes: int) -> str:
    """Format bytes as human-readable size."""
    if size_bytes == 0:
        return "0 B"
    for unit in ["B", "KB", "MB", "GB"]:
        if size_bytes < 1024:
            return f"{size_bytes:.1f} {unit}"
        size_bytes /= 1024
    return f"{size_bytes:.1f} TB"


@click.group()
def backends():
    """Manage AI backends and models."""
    pass


@backends.command("list")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_backends(api_url: str):
    """List all AI backends with status."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.list_backends()
        backends_data = response.get("data", response)

        table = Table(
            title="AI Backends",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("Backend", style="cyan", width=15)
        table.add_column("Status", width=12)
        table.add_column("Models", width=10)
        table.add_column("URL/Info", width=40)

        for name, info in backends_data.items():
            status = info.get("status", "unknown")
            status_style = "green" if status == "connected" else "red" if status == "error" else "yellow"
            status_str = f"[{status_style}]{status}[/{status_style}]"

            models_count = info.get("models_count", "-")
            url = info.get("url", info.get("info", ""))

            table.add_row(name, status_str, str(models_count), url[:40])

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list backends: {str(e)}")
        raise click.Abort()


@backends.command("models")
@click.argument("backend")
@click.option("--detailed", is_flag=True, help="Show detailed model info")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_models(backend: str, detailed: bool, api_url: str):
    """List models for a backend."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        models = client.list_backend_models(backend, detailed=detailed)

        if not models:
            console.print(f"[yellow]![/yellow] No models found for {backend}")
            console.print(f"Pull a model with: cw backends pull {backend} <model>")
            return

        table = Table(
            title=f"{backend.title()} Models ({len(models)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("Model", style="cyan", width=35)
        table.add_column("Size", width=12)

        if detailed:
            table.add_column("Parameters", width=12)
            table.add_column("Quantization", width=12)

        for model in models:
            name = model.get("name", model.get("model", "unknown"))
            size = format_size(model.get("size", 0))

            if detailed:
                params = model.get("parameters", "-")
                quant = model.get("quantization", "-")
                table.add_row(name, size, str(params), quant)
            else:
                table.add_row(name, size)

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list models: {str(e)}")
        raise click.Abort()


@backends.command("pull")
@click.argument("backend")
@click.argument("model")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def pull_model(backend: str, model: str, api_url: str):
    """Pull a model from a backend."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        console.print(f"[dim]Pulling {model} from {backend}...[/dim]")

        # Start the pull
        response = client.pull_model(backend, model)
        pull_id = response.get("pull_id")

        if not pull_id:
            # Pull completed immediately or no streaming needed
            console.print(f"[green]✓[/green] Model {model} is ready")
            return

        # Stream progress via SSE
        sse_client = ModelPullSSEClient(
            base_url=client.base_url,
            backend=backend,
            pull_id=pull_id,
            headers=client._get_headers(),
            timeout=1800,  # 30 min timeout for large models
        )

        try:
            with Progress(
                SpinnerColumn(),
                TextColumn("[progress.description]{task.description}"),
                BarColumn(),
                TaskProgressColumn(),
                console=console,
            ) as progress:
                task = progress.add_task(f"Pulling {model}...", total=100)
                current_status = ""

                for event_type, data in sse_client.events():
                    if event_type == "progress":
                        completed = data.get("completed", 0)
                        total = data.get("total", 100)
                        status = data.get("status", "")

                        if total > 0:
                            percent = (completed / total) * 100
                            progress.update(task, completed=percent)

                        if status != current_status:
                            current_status = status
                            progress.update(task, description=f"{status}...")

                    elif event_type == "completed":
                        progress.update(task, completed=100)
                        break

                    elif event_type == "failed":
                        error = data.get("error", "Unknown error")
                        console.print(f"\n[red]✗[/red] Pull failed: {error}")
                        raise click.Abort()

        finally:
            sse_client.close()

        console.print(f"[green]✓[/green] Model {model} pulled successfully")

    except click.Abort:
        raise
    except Exception as e:
        console.print(f"[red]✗[/red] Failed to pull model: {str(e)}")
        raise click.Abort()


@backends.command("show-model")
@click.argument("backend")
@click.argument("model")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_model(backend: str, model: str, api_url: str):
    """Show model details."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_model_info(backend, model)
        model_data = response.get("data", response)

        details = f"[bold]{model}[/bold]\n"
        details += f"Backend: {backend}\n"
        details += f"Size: {format_size(model_data.get('size', 0))}\n"

        if model_data.get("parameters"):
            details += f"Parameters: {model_data['parameters']}\n"
        if model_data.get("quantization"):
            details += f"Quantization: {model_data['quantization']}\n"
        if model_data.get("family"):
            details += f"Family: {model_data['family']}\n"
        if model_data.get("format"):
            details += f"Format: {model_data['format']}\n"

        console.print(Panel(
            details,
            title=f"Model: {model}",
            border_style="blue"
        ))

        # Show modelfile/template if available
        if model_data.get("template"):
            console.print("\n[bold]Template:[/bold]")
            console.print(Panel(model_data["template"][:500], border_style="dim"))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get model info: {str(e)}")
        raise click.Abort()


@backends.command("delete-model")
@click.argument("backend")
@click.argument("model")
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_model(backend: str, model: str, force: bool, api_url: str):
    """Delete a model."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        if not force:
            if not Confirm.ask(f"Delete model '{model}' from {backend}?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting model...", total=None)
            client.delete_model(backend, model)

        console.print(f"[green]✓[/green] Model {model} deleted from {backend}")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete model: {str(e)}")
        raise click.Abort()
