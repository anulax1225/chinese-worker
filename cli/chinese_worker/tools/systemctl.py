"""Systemctl tool for managing Linux systemd services."""

import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class SystemctlTool(BaseTool):
    """Manage systemd services on Linux."""

    @property
    def name(self) -> str:
        return "systemctl"

    @property
    def description(self) -> str:
        return "Manage systemd services: status, start, stop, restart, enable, disable, list"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "action": {
                    "type": "string",
                    "description": "Action: 'status', 'start', 'stop', 'restart', 'enable', 'disable', 'list', 'logs'",
                    "enum": ["status", "start", "stop", "restart", "enable", "disable", "list", "logs"],
                },
                "service": {
                    "type": "string",
                    "description": "Service name (not required for 'list' action)",
                },
                "user": {
                    "type": "boolean",
                    "description": "Use --user flag for user services (default: false)",
                },
                "lines": {
                    "type": "integer",
                    "description": "Number of log lines to show (default: 50, only for 'logs' action)",
                },
            },
            "required": ["action"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Execute systemctl command.

        Args:
            args: {"action": str, "service": str, "user": bool, "lines": int}

        Returns:
            Tuple of (success, output, error)
        """
        action = args.get("action")
        service = args.get("service")
        user_mode = args.get("user", False)
        lines = args.get("lines", 50)

        if not action:
            return False, "", "Missing 'action' argument"

        if action not in ["list"] and not service:
            return False, "", f"Missing 'service' argument for '{action}' action"

        try:
            if action == "list":
                return self._list_services(user_mode)
            elif action == "logs":
                return self._get_logs(service, user_mode, lines)
            else:
                return self._service_action(action, service, user_mode)
        except FileNotFoundError:
            return False, "", "systemctl not found - this tool is Linux only"
        except Exception as e:
            return False, "", f"Systemctl operation failed: {str(e)}"

    def _service_action(self, action: str, service: str, user_mode: bool) -> Tuple[bool, str, str]:
        """Execute a service action (status, start, stop, restart, enable, disable)."""
        cmd = ["systemctl"]

        if user_mode:
            cmd.append("--user")

        cmd.extend([action, service])

        # For start/stop/restart/enable/disable, we might need sudo
        needs_sudo = action in ["start", "stop", "restart", "enable", "disable"] and not user_mode

        if needs_sudo:
            cmd.insert(0, "sudo")

        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
        )

        output = result.stdout.strip()
        if result.stderr:
            output += f"\n{result.stderr.strip()}"

        # status returns non-zero for stopped services, but that's not an error
        if action == "status":
            return True, output, None

        if result.returncode == 0:
            return True, output if output else f"Service {service}: {action} completed", None
        else:
            return False, output, f"Action failed with code {result.returncode}"

    def _list_services(self, user_mode: bool) -> Tuple[bool, str, str]:
        """List all services."""
        cmd = ["systemctl", "list-units", "--type=service", "--no-pager"]

        if user_mode:
            cmd.insert(1, "--user")

        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, result.stdout.strip(), None
        else:
            return False, "", f"List failed: {result.stderr}"

    def _get_logs(self, service: str, user_mode: bool, lines: int) -> Tuple[bool, str, str]:
        """Get service logs using journalctl."""
        cmd = ["journalctl", "-u", service, "-n", str(lines), "--no-pager"]

        if user_mode:
            cmd.append("--user")

        result = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            output = result.stdout.strip()
            return True, output if output else "No logs found", None
        else:
            return False, "", f"Failed to get logs: {result.stderr}"
