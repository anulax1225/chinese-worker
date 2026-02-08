"""Windows Registry tool for reading and writing registry values."""

import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class RegistryTool(BaseTool):
    """Read and write Windows Registry values."""

    @property
    def name(self) -> str:
        return "registry"

    @property
    def description(self) -> str:
        return "Read, write, or delete Windows Registry values"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "action": {
                    "type": "string",
                    "description": "Action: 'read', 'write', 'delete', or 'list'",
                    "enum": ["read", "write", "delete", "list"],
                },
                "path": {
                    "type": "string",
                    "description": "Registry path (e.g., 'HKCU:\\Software\\MyApp')",
                },
                "name": {
                    "type": "string",
                    "description": "Value name (optional for 'list' action)",
                },
                "value": {
                    "type": "string",
                    "description": "Value to write (required for 'write' action)",
                },
                "value_type": {
                    "type": "string",
                    "description": "Value type for write: String, DWord, QWord, Binary, MultiString, ExpandString",
                    "enum": ["String", "DWord", "QWord", "Binary", "MultiString", "ExpandString"],
                },
            },
            "required": ["action", "path"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Execute registry operation.

        Args:
            args: {"action": str, "path": str, "name": str, "value": str, "value_type": str}

        Returns:
            Tuple of (success, output, error)
        """
        action = args.get("action")
        path = args.get("path")

        if not action:
            return False, "", "Missing 'action' argument"
        if not path:
            return False, "", "Missing 'path' argument"

        try:
            if action == "read":
                return self._read(path, args.get("name"))
            elif action == "write":
                return self._write(path, args.get("name"), args.get("value"), args.get("value_type", "String"))
            elif action == "delete":
                return self._delete(path, args.get("name"))
            elif action == "list":
                return self._list(path)
            else:
                return False, "", f"Invalid action: {action}"
        except FileNotFoundError:
            return False, "", "PowerShell not found - this tool is Windows only"
        except Exception as e:
            return False, "", f"Registry operation failed: {str(e)}"

    def _read(self, path: str, name: str | None) -> Tuple[bool, str, str]:
        """Read a registry value."""
        if name:
            ps_cmd = f"(Get-ItemProperty -Path '{path}' -Name '{name}').'{name}'"
        else:
            ps_cmd = f"Get-ItemProperty -Path '{path}'"

        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, result.stdout.strip(), None
        else:
            return False, "", f"Read failed: {result.stderr}"

    def _write(self, path: str, name: str | None, value: str | None, value_type: str) -> Tuple[bool, str, str]:
        """Write a registry value."""
        if not name:
            return False, "", "Missing 'name' argument for write action"
        if value is None:
            return False, "", "Missing 'value' argument for write action"

        # Create the path if it doesn't exist
        ps_cmd = f"""
        if (!(Test-Path '{path}')) {{
            New-Item -Path '{path}' -Force | Out-Null
        }}
        Set-ItemProperty -Path '{path}' -Name '{name}' -Value '{value}' -Type {value_type}
        """

        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, f"Set {path}\\{name} = {value}", None
        else:
            return False, "", f"Write failed: {result.stderr}"

    def _delete(self, path: str, name: str | None) -> Tuple[bool, str, str]:
        """Delete a registry value or key."""
        if name:
            ps_cmd = f"Remove-ItemProperty -Path '{path}' -Name '{name}' -Force"
            target = f"{path}\\{name}"
        else:
            ps_cmd = f"Remove-Item -Path '{path}' -Recurse -Force"
            target = path

        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, f"Deleted: {target}", None
        else:
            return False, "", f"Delete failed: {result.stderr}"

    def _list(self, path: str) -> Tuple[bool, str, str]:
        """List registry values and subkeys."""
        ps_cmd = f"""
        $output = @()

        # List values
        $props = Get-ItemProperty -Path '{path}' -ErrorAction SilentlyContinue
        if ($props) {{
            $output += "Values:"
            $props.PSObject.Properties | Where-Object {{ $_.Name -notlike 'PS*' }} | ForEach-Object {{
                $output += "  $($_.Name) = $($_.Value)"
            }}
        }}

        # List subkeys
        $subkeys = Get-ChildItem -Path '{path}' -ErrorAction SilentlyContinue
        if ($subkeys) {{
            $output += ""
            $output += "Subkeys:"
            $subkeys | ForEach-Object {{
                $output += "  $($_.PSChildName)"
            }}
        }}

        $output -join "`n"
        """

        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps_cmd],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            output = result.stdout.strip()
            return True, output if output else "No values or subkeys found", None
        else:
            return False, "", f"List failed: {result.stderr}"
