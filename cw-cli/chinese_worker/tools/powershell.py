"""PowerShell tool for Windows command execution."""

import os
import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class PowerShellTool(BaseTool):
    """Execute PowerShell commands on Windows."""

    @property
    def name(self) -> str:
        return "powershell"

    @property
    def description(self) -> str:
        return "Execute a PowerShell command on the Windows system"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "command": {
                    "type": "string",
                    "description": "The PowerShell command to execute",
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
        Execute a PowerShell command.

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
            result = subprocess.run(
                ["powershell", "-NoProfile", "-NonInteractive", "-Command", command],
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
        except FileNotFoundError:
            return False, "", "PowerShell not found on this system"
        except Exception as e:
            return False, "", f"Failed to execute command: {str(e)}"
