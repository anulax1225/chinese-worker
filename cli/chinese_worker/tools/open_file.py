"""Open tool for launching files and URLs with default applications."""

import os
import platform
import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class OpenTool(BaseTool):
    """Open files or URLs with the system's default application."""

    @property
    def name(self) -> str:
        return "open"

    @property
    def description(self) -> str:
        return "Open a file or URL with the system's default application"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "path": {
                    "type": "string",
                    "description": "File path or URL to open",
                },
                "application": {
                    "type": "string",
                    "description": "Specific application to use (optional, macOS/Linux only)",
                },
            },
            "required": ["path"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Open a file or URL.

        Args:
            args: {"path": str, "application": str (optional)}

        Returns:
            Tuple of (success, output, error)
        """
        path = args.get("path")
        if not path:
            return False, "", "Missing 'path' argument"

        application = args.get("application")
        system = platform.system().lower()

        try:
            if system == "darwin":
                return self._open_macos(path, application)
            elif system == "windows":
                return self._open_windows(path)
            else:
                return self._open_linux(path, application)
        except FileNotFoundError as e:
            return False, "", f"Open command not found: {str(e)}"
        except Exception as e:
            return False, "", f"Failed to open: {str(e)}"

    def _open_macos(self, path: str, application: str | None) -> Tuple[bool, str, str]:
        """Open on macOS using 'open' command."""
        cmd = ["open"]

        if application:
            cmd.extend(["-a", application])

        cmd.append(path)

        result = subprocess.run(cmd, capture_output=True, text=True)

        if result.returncode == 0:
            return True, f"Opened: {path}", None
        else:
            return False, "", f"Failed to open: {result.stderr}"

    def _open_windows(self, path: str) -> Tuple[bool, str, str]:
        """Open on Windows using Start-Process."""
        # Use os.startfile for simplicity on Windows
        try:
            os.startfile(path)  # type: ignore[attr-defined]
            return True, f"Opened: {path}", None
        except OSError as e:
            return False, "", f"Failed to open: {str(e)}"

    def _open_linux(self, path: str, application: str | None) -> Tuple[bool, str, str]:
        """Open on Linux using xdg-open or specified application."""
        if application:
            cmd = [application, path]
        else:
            cmd = ["xdg-open", path]

        result = subprocess.run(cmd, capture_output=True, text=True)

        if result.returncode == 0:
            return True, f"Opened: {path}", None
        else:
            return False, "", f"Failed to open: {result.stderr}"
