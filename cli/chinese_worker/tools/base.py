"""Base tool class."""

from abc import ABC, abstractmethod
from typing import Dict, Any, Tuple


class BaseTool(ABC):
    """Base class for all builtin tools."""

    @property
    @abstractmethod
    def name(self) -> str:
        """Tool name."""
        pass

    @abstractmethod
    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Execute the tool with given arguments.

        Args:
            args: Tool arguments from server

        Returns:
            Tuple of (success, output, error)
        """
        pass
