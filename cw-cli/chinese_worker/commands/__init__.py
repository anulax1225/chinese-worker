"""Command groups for Chinese Worker CLI."""

from .agents import agents
from .tools import tools
from .prompts import prompts
from .docs import docs
from .backends import backends
from .files import files
from .conversations import conversations

__all__ = [
    "agents",
    "tools",
    "prompts",
    "docs",
    "backends",
    "files",
    "conversations",
]
