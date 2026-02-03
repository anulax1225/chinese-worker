"""Builtin tools for CLI execution."""

# OS-specific shell tools
from .bash import BashTool
from .powershell import PowerShellTool

# Universal file tools
from .edit import EditTool
from .glob import GlobTool
from .grep import GrepTool
from .read import ReadTool
from .write import WriteTool

# Cross-platform tools
from .clipboard import ClipboardTool
from .notify import NotifyTool
from .open_file import OpenTool
from .sysinfo import SysInfoTool

# OS-specific advanced tools
from .applescript import AppleScriptTool
from .registry import RegistryTool
from .systemctl import SystemctlTool

__all__ = [
    # Shell tools
    "BashTool",
    "PowerShellTool",
    # File tools
    "EditTool",
    "GlobTool",
    "GrepTool",
    "ReadTool",
    "WriteTool",
    # Cross-platform tools
    "ClipboardTool",
    "NotifyTool",
    "OpenTool",
    "SysInfoTool",
    # OS-specific tools
    "AppleScriptTool",
    "RegistryTool",
    "SystemctlTool",
]
