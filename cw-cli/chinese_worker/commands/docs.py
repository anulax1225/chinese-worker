"""Document management commands."""

import os
import click
from rich.console import Console
from rich.panel import Panel
from rich.prompt import Confirm
from rich.table import Table
from rich.progress import Progress, SpinnerColumn, TextColumn, BarColumn

from ..api import APIClient, AuthManager

console = Console()


def get_default_api_url() -> str:
    """Get default API URL from environment or use localhost."""
    return os.getenv("CW_API_URL", "http://localhost")


def format_size(size_bytes: int) -> str:
    """Format bytes as human-readable size."""
    for unit in ["B", "KB", "MB", "GB"]:
        if size_bytes < 1024:
            return f"{size_bytes:.1f} {unit}"
        size_bytes /= 1024
    return f"{size_bytes:.1f} TB"


@click.group()
def docs():
    """Manage documents."""
    pass


@docs.command("list")
@click.option("--status", type=click.Choice(["pending", "extracting", "cleaning", "normalizing", "chunking", "ready", "failed"]), help="Filter by status")
@click.option("--search", help="Search query")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def list_docs(status: str, search: str, api_url: str):
    """List all documents."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        docs_list = client.list_documents(status=status, search=search)

        if not docs_list:
            console.print("[yellow]![/yellow] No documents found")
            console.print("Upload one with: cw docs upload <file>")
            return

        table = Table(
            title=f"Documents ({len(docs_list)})",
            show_header=True,
            header_style="bold cyan"
        )
        table.add_column("ID", style="cyan", width=6)
        table.add_column("Title", width=30)
        table.add_column("Status", width=12)
        table.add_column("Chunks", width=8)
        table.add_column("Size", width=10)

        for doc in docs_list:
            status_val = doc.get("status", "unknown")
            status_style = {
                "ready": "green",
                "failed": "red",
                "pending": "yellow",
                "extracting": "blue",
                "cleaning": "blue",
                "normalizing": "blue",
                "chunking": "blue",
            }.get(status_val, "")

            status_str = f"[{status_style}]{status_val}[/{status_style}]" if status_style else status_val

            table.add_row(
                str(doc["id"]),
                doc.get("title", doc.get("filename", "Untitled"))[:30],
                status_str,
                str(doc.get("chunks_count", 0)),
                format_size(doc.get("size", 0)),
            )

        console.print(table)

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to list documents: {str(e)}")
        raise click.Abort()


@docs.command("show")
@click.argument("doc_id", type=int)
@click.option("--stages", is_flag=True, help="Show processing stages")
@click.option("--chunks", is_flag=True, help="Show document chunks")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def show_doc(doc_id: int, stages: bool, chunks: bool, api_url: str):
    """Show document details."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        response = client.get_document(doc_id)
        doc = response.get("data", response)

        # Build details
        status = doc.get("status", "unknown")
        status_style = "green" if status == "ready" else "yellow" if status != "failed" else "red"

        details = f"[bold]{doc.get('title', 'Untitled')}[/bold]\n"
        details += f"Status: [{status_style}]{status}[/{status_style}]\n"
        details += f"Size: {format_size(doc.get('size', 0))}\n"
        details += f"Chunks: {doc.get('chunks_count', 0)}\n"

        if doc.get("filename"):
            details += f"Filename: {doc['filename']}\n"
        if doc.get("mime_type"):
            details += f"Type: {doc['mime_type']}\n"

        console.print(Panel(
            details,
            title=f"Document #{doc['id']}",
            border_style="blue"
        ))

        # Show stages if requested
        if stages:
            console.print("\n[bold]Processing Stages:[/bold]")
            stages_list = client.get_document_stages(doc_id)

            for stage in stages_list:
                stage_status = stage.get("status", "unknown")
                icon = "✓" if stage_status == "completed" else "✗" if stage_status == "failed" else "○"
                color = "green" if stage_status == "completed" else "red" if stage_status == "failed" else "yellow"
                console.print(f"  [{color}]{icon}[/{color}] {stage.get('name', 'Unknown')}")
                if stage.get("error"):
                    console.print(f"      [red]Error: {stage['error']}[/red]")

        # Show chunks if requested
        if chunks:
            console.print("\n[bold]Chunks:[/bold]")
            chunks_list = client.get_document_chunks(doc_id, per_page=10)

            for i, chunk in enumerate(chunks_list, 1):
                content = chunk.get("content", "")[:100]
                console.print(f"\n  [cyan]Chunk {i}:[/cyan]")
                console.print(f"  {content}...")

            if len(chunks_list) == 10:
                console.print(f"\n  [dim]Showing first 10 chunks...[/dim]")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get document: {str(e)}")
        raise click.Abort()


@docs.command("upload")
@click.argument("file_path", type=click.Path(exists=True))
@click.option("--title", help="Document title")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def upload_doc(file_path: str, title: str, api_url: str):
    """Upload a document file."""
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
            response = client.upload_document(file_path, title=title)

        doc = response.get("data", response)
        console.print(f"[green]✓[/green] Document uploaded (ID: {doc.get('id', 'N/A')})")
        console.print(f"[dim]Status: {doc.get('status', 'pending')} - processing will begin shortly[/dim]")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to upload document: {str(e)}")
        raise click.Abort()


@docs.command("upload-url")
@click.argument("url")
@click.option("--title", help="Document title")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def upload_url(url: str, title: str, api_url: str):
    """Ingest a document from a URL."""
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
            progress.add_task("Fetching URL...", total=None)
            response = client.upload_document_from_url(url, title=title)

        doc = response.get("data", response)
        console.print(f"[green]✓[/green] Document created from URL (ID: {doc.get('id', 'N/A')})")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to ingest URL: {str(e)}")
        raise click.Abort()


@docs.command("preview")
@click.argument("doc_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def preview_doc(doc_id: int, api_url: str):
    """Show document preview comparison."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        preview = client.get_document_preview(doc_id)

        console.print("[bold]Original Content:[/bold]")
        console.print(Panel(
            preview.get("original", "(not available)")[:500],
            border_style="dim"
        ))

        console.print("\n[bold]Processed Content:[/bold]")
        console.print(Panel(
            preview.get("processed", "(not available)")[:500],
            border_style="green"
        ))

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get preview: {str(e)}")
        raise click.Abort()


@docs.command("reprocess")
@click.argument("doc_id", type=int)
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def reprocess_doc(doc_id: int, api_url: str):
    """Reprocess a document."""
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
            progress.add_task("Reprocessing...", total=None)
            response = client.reprocess_document(doc_id)

        doc = response.get("data", response)
        console.print(f"[green]✓[/green] Document {doc_id} queued for reprocessing")
        console.print(f"[dim]Status: {doc.get('status', 'pending')}[/dim]")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to reprocess document: {str(e)}")
        raise click.Abort()


@docs.command("delete")
@click.argument("doc_id", type=int)
@click.option("--force", "-f", is_flag=True, help="Skip confirmation")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def delete_doc(doc_id: int, force: bool, api_url: str):
    """Delete a document."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        if not force:
            response = client.get_document(doc_id)
            doc = response.get("data", response)
            title = doc.get("title", doc.get("filename", "Untitled"))

            if not Confirm.ask(f"Delete document '{title}'?", default=False):
                console.print("[dim]Cancelled[/dim]")
                return

        with Progress(
            SpinnerColumn(),
            TextColumn("[progress.description]{task.description}"),
            console=console,
        ) as progress:
            progress.add_task("Deleting...", total=None)
            client.delete_document(doc_id)

        console.print(f"[green]✓[/green] Document {doc_id} deleted")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to delete document: {str(e)}")
        raise click.Abort()


@docs.command("types")
@click.option("--api-url", default=get_default_api_url(), help="API base URL")
def supported_types(api_url: str):
    """Show supported document types."""
    if not AuthManager.is_authenticated():
        console.print("[yellow]![/yellow] You are not logged in")
        console.print("Run 'cw login' to authenticate")
        return

    client = APIClient(api_url)

    try:
        types = client.get_supported_document_types()

        console.print("[bold]Supported Document Types:[/bold]\n")
        for mime_type in sorted(types):
            console.print(f"  - {mime_type}")

    except Exception as e:
        console.print(f"[red]✗[/red] Failed to get supported types: {str(e)}")
        raise click.Abort()
