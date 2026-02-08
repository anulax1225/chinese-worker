"""System information tool using psutil."""

import platform
from typing import Any, Dict, Tuple

import psutil

from .base import BaseTool


class SysInfoTool(BaseTool):
    """Get system information like CPU, memory, disk, and processes."""

    @property
    def name(self) -> str:
        return "sysinfo"

    @property
    def description(self) -> str:
        return "Get system information: CPU, memory, disk usage, or running processes"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "info_type": {
                    "type": "string",
                    "description": "Type of info: 'cpu', 'memory', 'disk', 'processes', 'system', or 'all'",
                    "enum": ["cpu", "memory", "disk", "processes", "system", "all"],
                },
                "top_n": {
                    "type": "integer",
                    "description": "Number of top processes to show (default: 10, only for 'processes')",
                },
            },
            "required": ["info_type"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Get system information.

        Args:
            args: {"info_type": str, "top_n": int (optional)}

        Returns:
            Tuple of (success, output, error)
        """
        info_type = args.get("info_type")
        if not info_type:
            return False, "", "Missing 'info_type' argument"

        top_n = args.get("top_n", 10)

        try:
            if info_type == "cpu":
                return True, self._get_cpu_info(), None
            elif info_type == "memory":
                return True, self._get_memory_info(), None
            elif info_type == "disk":
                return True, self._get_disk_info(), None
            elif info_type == "processes":
                return True, self._get_processes(top_n), None
            elif info_type == "system":
                return True, self._get_system_info(), None
            elif info_type == "all":
                output = "\n\n".join([
                    "=== SYSTEM ===\n" + self._get_system_info(),
                    "=== CPU ===\n" + self._get_cpu_info(),
                    "=== MEMORY ===\n" + self._get_memory_info(),
                    "=== DISK ===\n" + self._get_disk_info(),
                    f"=== TOP {top_n} PROCESSES ===\n" + self._get_processes(top_n),
                ])
                return True, output, None
            else:
                return False, "", f"Invalid info_type: {info_type}"
        except Exception as e:
            return False, "", f"Failed to get system info: {str(e)}"

    def _get_system_info(self) -> str:
        """Get basic system information."""
        uname = platform.uname()
        return "\n".join([
            f"System: {uname.system}",
            f"Node Name: {uname.node}",
            f"Release: {uname.release}",
            f"Version: {uname.version}",
            f"Machine: {uname.machine}",
            f"Processor: {uname.processor}",
            f"Python: {platform.python_version()}",
        ])

    def _get_cpu_info(self) -> str:
        """Get CPU information."""
        cpu_percent = psutil.cpu_percent(interval=1, percpu=True)
        cpu_freq = psutil.cpu_freq()
        cpu_count_logical = psutil.cpu_count(logical=True)
        cpu_count_physical = psutil.cpu_count(logical=False)

        lines = [
            f"Physical cores: {cpu_count_physical}",
            f"Logical cores: {cpu_count_logical}",
        ]

        if cpu_freq:
            lines.append(f"Current frequency: {cpu_freq.current:.0f} MHz")
            if cpu_freq.max:
                lines.append(f"Max frequency: {cpu_freq.max:.0f} MHz")

        lines.append(f"Total CPU usage: {sum(cpu_percent) / len(cpu_percent):.1f}%")
        lines.append("Per-core usage: " + ", ".join(f"{p:.1f}%" for p in cpu_percent))

        return "\n".join(lines)

    def _get_memory_info(self) -> str:
        """Get memory information."""
        mem = psutil.virtual_memory()
        swap = psutil.swap_memory()

        def format_bytes(b: int) -> str:
            for unit in ["B", "KB", "MB", "GB", "TB"]:
                if b < 1024:
                    return f"{b:.1f} {unit}"
                b /= 1024
            return f"{b:.1f} PB"

        lines = [
            "RAM:",
            f"  Total: {format_bytes(mem.total)}",
            f"  Available: {format_bytes(mem.available)}",
            f"  Used: {format_bytes(mem.used)} ({mem.percent}%)",
            "",
            "Swap:",
            f"  Total: {format_bytes(swap.total)}",
            f"  Used: {format_bytes(swap.used)} ({swap.percent}%)",
            f"  Free: {format_bytes(swap.free)}",
        ]

        return "\n".join(lines)

    def _get_disk_info(self) -> str:
        """Get disk usage information."""
        def format_bytes(b: int) -> str:
            for unit in ["B", "KB", "MB", "GB", "TB"]:
                if b < 1024:
                    return f"{b:.1f} {unit}"
                b /= 1024
            return f"{b:.1f} PB"

        lines = []
        for partition in psutil.disk_partitions(all=False):
            try:
                usage = psutil.disk_usage(partition.mountpoint)
                lines.extend([
                    f"{partition.device} ({partition.mountpoint}):",
                    f"  Filesystem: {partition.fstype}",
                    f"  Total: {format_bytes(usage.total)}",
                    f"  Used: {format_bytes(usage.used)} ({usage.percent}%)",
                    f"  Free: {format_bytes(usage.free)}",
                    "",
                ])
            except (PermissionError, OSError):
                continue

        return "\n".join(lines).rstrip()

    def _get_processes(self, top_n: int = 10) -> str:
        """Get top N processes by CPU usage."""
        processes = []
        for proc in psutil.process_iter(["pid", "name", "cpu_percent", "memory_percent"]):
            try:
                info = proc.info
                processes.append({
                    "pid": info["pid"],
                    "name": info["name"][:30],
                    "cpu": info["cpu_percent"] or 0,
                    "mem": info["memory_percent"] or 0,
                })
            except (psutil.NoSuchProcess, psutil.AccessDenied):
                continue

        # Sort by CPU usage
        processes.sort(key=lambda x: x["cpu"], reverse=True)

        lines = [f"{'PID':<8} {'Name':<32} {'CPU%':<8} {'MEM%':<8}"]
        lines.append("-" * 60)
        for p in processes[:top_n]:
            lines.append(f"{p['pid']:<8} {p['name']:<32} {p['cpu']:<8.1f} {p['mem']:<8.1f}")

        return "\n".join(lines)
