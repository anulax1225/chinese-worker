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

    @property
    @abstractmethod
    def description(self) -> str:
        """Tool description."""
        pass

    @property
    @abstractmethod
    def parameters(self) -> Dict[str, Any]:
        """Tool parameters schema (JSON Schema format)."""
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

    def get_schema(self) -> Dict[str, Any]:
        """Get the full tool schema for API registration."""
        return {
            "name": self.name,
            "description": self.description,
            "parameters": self.parameters,
        }
