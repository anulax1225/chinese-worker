"""Bash tool for executing shell commands."""

import subprocess
import os
from typing import Dict, Any, Tuple
from .base import BaseTool


class BashTool(BaseTool):
    """Execute bash commands on the local system."""

    @property
    def name(self) -> str:
        return "bash"

    @property
    def description(self) -> str:
        return "Execute a bash command on the client system"

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
            result = subprocess.run(
                command,
                shell=True,
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
        except Exception as e:
            return False, "", f"Failed to execute command: {str(e)}"
