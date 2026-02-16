"""File management commands."""

import os
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
def files():
    """Manage files."""
    pass


@files.command("list")
@click.option("--type", "type_filter", type=click.Choice(["input", "output", "temp"]), help="Filter by type")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_files(type_filter: str, api_url: str):
    """List all files."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        files_list = client.list_files(type_filter=type_filter)

        if not files_list:
            console.print("[yellow]![/yellow] No files found")
            console.print("Upload one with: cw files upload <file>")
            return

        table = Table(
            title=f"Files ({len(files_list)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("ID", style="cyan", width=6)
        table.add_column("Name", width=35)
        table.add_column("Type", width=10)
        table.add_column("Size", width=12)
        table.add_column("MIME", width=20)

        for f in files_list:
            file_type = f.get("type", "unknown")
            type_style = {
                "input": "green",
                "output": "blue",
                "temp": "dim",
            }.get(file_type, "")

            type_str = f"[{type_style}]{file_type}[/{type_style}]" if type_style else file_type

            table.add_row(
                str(f["id"]),
                f.get("name", f.get("filename", "Unknown"))[:35],
                type_str,
                format_size(f.get("size", 0)),
                f.get("mime_type", "")[:20],
            )

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list files: {str(e)}")
        raise click.Abort()


@files.command("show")
@click.argument("file_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_file(file_id: int, api_url: str):
    """Show file details."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_file(file_id)
        f = response.get("data", response)

        details = f"[bold]{f.get('name', f.get('filename', 'Unknown'))}[/bold]\n"
        details += f"Type: {f.get('type', 'unknown')}\n"
        details += f"Size: {format_size(f.get('size', 0))}\n"
        details += f"MIME: {f.get('mime_type', 'unknown')}\n"

        if f.get("path"):
            details += f"Path: {f['path']}\n"
        if f.get("created_at"):
            details += f"Created: {f['created_at']}\n"

        console.print(Panel(
            details,
            title=f"File #{f['id']}",
            border_style="blue"
        ))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get file: {str(e)}")
        raise click.Abort()


@files.command("upload")
@click.argument("file_path", type=click.Path(exists=True))
@click.option("--type", "file_type", type=click.Choice(["input", "output", "temp"]), default="input", help="File type")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def upload_file(file_path: str, file_type: str, api_url: str):
    """Upload a file."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        file_size = os.path.getsize(file_path)
        console.print(f"[dim]Uploading {os.path.basename(file_path)} ({format_size(file_size)})...[/dim]")

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Uploading...", total=None)
            response = client.upload_file(file_path, file_type=file_type)

        f = response.get("data", response)
        console.print(f"[green]✓[/green] File uploaded (ID: {f.get('id', 'N/A')})")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to upload file: {str(e)}")
        raise click.Abort()


@files.command("download")
@click.argument("file_id", type=int)
@click.option("--output", "-o", type=click.Path(), help="Output file path")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def download_file(file_id: int, output: str, api_url: str):
    """Download a file."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        # Get file info first for the filename
        file_info = client.get_file(file_id)
        f = file_info.get("data", file_info)
        filename = f.get("name", f.get("filename", f"file_{file_id}"))

        if output is None:
            output = filename

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Downloading...", total=None)
            content = client.download_file(file_id)

        with open(output, "wb") as out_file:
            out_file.write(content)

        console.print(f"[green]✓[/green] Downloaded to {output} ({format_size(len(content))})")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to download file: {str(e)}")
        raise click.Abort()


@files.command("delete")
@click.argument("file_id", type=int)
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_file(file_id: int, force: bool, api_url: str):
    """Delete a file."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        if not force:
            response = client.get_file(file_id)
            f = response.get("data", response)
            filename = f.get("name", f.get("filename", "Unknown"))

            if not Confirm.ask(f"Delete file '{filename}'?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting...", total=None)
            client.delete_file(file_id)

        console.print(f"[green]✓[/green] File {file_id} deleted")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete file: {str(e)}")
        raise click.Abort()
