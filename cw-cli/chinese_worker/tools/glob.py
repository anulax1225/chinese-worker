"""Glob tool for file pattern matching."""

import os
from pathlib import Path
from typing import Any, Dict, Tuple

from .base import BaseTool


class GlobTool(BaseTool):
    """Find files matching glob patterns."""

    @property
    def name(self) -> str:
        return "glob"

    @property
    def description(self) -> str:
        return "Find files matching a glob pattern"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "pattern": {
                    "type": "string",
                    "description": "Glob pattern to match files (e.g., '**/*.py')",
                },
                "path": {
                    "type": "string",
                    "description": "Directory to search in (default: current directory)",
                },
            },
            "required": ["pattern"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Find files matching a glob pattern.

        Args:
            args: {"pattern": str, "path": str (optional, default cwd)}

        Returns:
            Tuple of (success, output, error)
        """
        pattern = args.get("pattern")
        if not pattern:
            return False, "", "Missing 'pattern' argument"

        search_path = args.get("path", os.getcwd())

        # Use pathlib for cross-platform path handling
        search_dir = Path(search_path)
        if not search_dir.is_absolute():
            search_dir = Path.cwd() / search_dir

        try:
            # Resolve to handle any symlinks and normalize
            search_dir = search_dir.resolve()

            if not search_dir.exists():
                return False, "", f"Directory not found: {search_dir}"

            if not search_dir.is_dir():
                return False, "", f"Path is not a directory: {search_dir}"

            # Use pathlib.glob instead of os.chdir + glob module
            # This is thread-safe and works with Windows UNC paths
            matches = list(search_dir.glob(pattern))

            # Filter to files only and get absolute paths
            abs_matches = [str(m.resolve()) for m in matches if m.is_file()]

            # Sort by modification time (newest first)
            abs_matches.sort(
                key=lambda x: Path(x).stat().st_mtime if Path(x).exists() else 0,
                reverse=True,
            )

            if not abs_matches:
                output = f"No files found matching pattern: {pattern}"
            else:
                output = "\n".join(abs_matches)

            return True, output, None

        except Exception as e:
            return False, "", f"Failed to glob files: {str(e)}"
