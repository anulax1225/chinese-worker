"""Bash tool for executing shell commands."""

import os
import platform
import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class BashTool(BaseTool):
    """Execute shell commands on the local system (cross-platform)."""

    @property
    def name(self) -> str:
        return "bash"

    @property
    def description(self) -> str:
        return "Execute a shell command on the client system"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "command": {
                    "type": "string",
                    "description": "The bash command to execute",
                },
                "timeout": {
                    "type": "integer",
                    "description": "Timeout in seconds (default: 120)",
                },
            },
            "required": ["command"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Execute a bash command.

        Args:
            args: {"command": str, "timeout": int (optional, default 120)}

        Returns:
            Tuple of (success, output, error)
        """
        command = args.get("command")
        if not command:
            return False, "", "Missing 'command' argument"

        timeout = args.get("timeout", 120)

        try:
            # Platform-specific shell invocation
            system = platform.system().lower()
            if system == "windows":
                # Use PowerShell on Windows for better compatibility
                result = subprocess.run(
                    ["powershell", "-NoProfile", "-NonInteractive", "-Command", command],
                    capture_output=True,
                    text=True,
                    timeout=timeout,
                    cwd=os.getcwd(),
                )
            else:
                # Use bash explicitly on Unix-like systems (Linux, macOS)
                result = subprocess.run(
                    ["bash", "-c", command],
                    capture_output=True,
                    text=True,
                    timeout=timeout,
                    cwd=os.getcwd(),
                )

            # Combine stdout and stderr for output
            output = result.stdout
            if result.stderr:
                output += f"\n{result.stderr}"

            success = result.returncode == 0
            error = None if success else f"Command exited with code {result.returncode}"

            return success, output.strip(), error

        except subprocess.TimeoutExpired:
            return False, "", f"Command timed out after {timeout} seconds"
        except FileNotFoundError as e:
            shell_name = "PowerShell" if platform.system().lower() == "windows" else "bash"
            return False, "", f"{shell_name} not found: {str(e)}"
        except Exception as e:
            return False, "", f"Failed to execute command: {str(e)}"
