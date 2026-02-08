"""Clipboard tool for cross-platform clipboard access."""

import platform
import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class ClipboardTool(BaseTool):
    """Copy and paste text using the system clipboard."""

    @property
    def name(self) -> str:
        return "clipboard"

    @property
    def description(self) -> str:
        return "Copy text to or paste text from the system clipboard"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "action": {
                    "type": "string",
                    "description": "Action to perform: 'copy' or 'paste'",
                    "enum": ["copy", "paste"],
                },
                "text": {
                    "type": "string",
                    "description": "Text to copy (required for 'copy' action)",
                },
            },
            "required": ["action"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Execute clipboard operation.

        Args:
            args: {"action": "copy"|"paste", "text": str (for copy)}

        Returns:
            Tuple of (success, output, error)
        """
        action = args.get("action")
        if not action:
            return False, "", "Missing 'action' argument"

        if action not in ["copy", "paste"]:
            return False, "", f"Invalid action: {action}. Must be 'copy' or 'paste'"

        system = platform.system().lower()

        try:
            if action == "copy":
                return self._copy(args.get("text", ""), system)
            else:
                return self._paste(system)
        except FileNotFoundError as e:
            return False, "", f"Clipboard command not found: {str(e)}"
        except Exception as e:
            return False, "", f"Clipboard operation failed: {str(e)}"

    def _copy(self, text: str, system: str) -> Tuple[bool, str, str]:
        """Copy text to clipboard."""
        if not text:
            return False, "", "Missing 'text' argument for copy action"

        if system == "darwin":
            # macOS: use pbcopy
            process = subprocess.run(
                ["pbcopy"],
                input=text,
                capture_output=True,
                text=True,
            )
        elif system == "windows":
            # Windows: use PowerShell Set-Clipboard
            process = subprocess.run(
                ["powershell", "-NoProfile", "-Command", f"Set-Clipboard -Value '{text}'"],
                capture_output=True,
                text=True,
            )
        else:
            # Linux: try xclip first, fall back to xsel
            try:
                process = subprocess.run(
                    ["xclip", "-selection", "clipboard"],
                    input=text,
                    capture_output=True,
                    text=True,
                )
            except FileNotFoundError:
                process = subprocess.run(
                    ["xsel", "--clipboard", "--input"],
                    input=text,
                    capture_output=True,
                    text=True,
                )

        if process.returncode == 0:
            return True, f"Copied {len(text)} characters to clipboard", None
        else:
            return False, "", f"Copy failed: {process.stderr}"

    def _paste(self, system: str) -> Tuple[bool, str, str]:
        """Paste text from clipboard."""
        if system == "darwin":
            # macOS: use pbpaste
            process = subprocess.run(
                ["pbpaste"],
                capture_output=True,
                text=True,
            )
        elif system == "windows":
            # Windows: use PowerShell Get-Clipboard
            process = subprocess.run(
                ["powershell", "-NoProfile", "-Command", "Get-Clipboard"],
                capture_output=True,
                text=True,
            )
        else:
            # Linux: try xclip first, fall back to xsel
            try:
                process = subprocess.run(
                    ["xclip", "-selection", "clipboard", "-o"],
                    capture_output=True,
                    text=True,
                )
            except FileNotFoundError:
                process = subprocess.run(
                    ["xsel", "--clipboard", "--output"],
                    capture_output=True,
                    text=True,
                )

        if process.returncode == 0:
            return True, process.stdout, None
        else:
            return False, "", f"Paste failed: {process.stderr}"
