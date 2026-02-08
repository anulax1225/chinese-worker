"""AppleScript tool for macOS automation."""

import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class AppleScriptTool(BaseTool):
    """Execute AppleScript code on macOS."""

    @property
    def name(self) -> str:
        return "applescript"

    @property
    def description(self) -> str:
        return "Execute AppleScript code for macOS automation"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "script": {
                    "type": "string",
                    "description": "AppleScript code to execute",
                },
                "language": {
                    "type": "string",
                    "description": "Script language: 'applescript' (default) or 'javascript'",
                    "enum": ["applescript", "javascript"],
                },
            },
            "required": ["script"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Execute AppleScript.

        Args:
            args: {"script": str, "language": str (optional)}

        Returns:
            Tuple of (success, output, error)
        """
        script = args.get("script")
        if not script:
            return False, "", "Missing 'script' argument"

        language = args.get("language", "applescript")

        try:
            cmd = ["osascript"]

            if language == "javascript":
                cmd.extend(["-l", "JavaScript"])

            cmd.extend(["-e", script])

            result = subprocess.run(
                cmd,
                capture_output=True,
                text=True,
                timeout=60,
            )

            output = result.stdout.strip()
            if result.returncode == 0:
                return True, output if output else "Script executed successfully", None
            else:
                return False, output, f"Script failed: {result.stderr}"

        except subprocess.TimeoutExpired:
            return False, "", "Script execution timed out after 60 seconds"
        except FileNotFoundError:
            return False, "", "osascript not found - this tool is macOS only"
        except Exception as e:
            return False, "", f"Failed to execute script: {str(e)}"
