"""Glob tool for file pattern matching."""

import os
import glob as glob_module
from pathlib import Path
from typing import Dict, Any, Tuple
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

        # Make path absolute if relative
        if not os.path.isabs(search_path):
            search_path = os.path.join(os.getcwd(), search_path)

        try:
            if not os.path.exists(search_path):
                return False, "", f"Directory not found: {search_path}"

            if not os.path.isdir(search_path):
                return False, "", f"Path is not a directory: {search_path}"

            # Change to search directory and perform glob
            original_cwd = os.getcwd()
            try:
                os.chdir(search_path)
                matches = glob_module.glob(pattern, recursive=True)

                # Convert to absolute paths and sort by modification time (newest first)
                abs_matches = [os.path.abspath(m) for m in matches if os.path.isfile(m)]
                abs_matches.sort(
                    key=lambda x: os.path.getmtime(x) if os.path.exists(x) else 0,
                    reverse=True,
                )

                if not abs_matches:
                    output = f"No files found matching pattern: {pattern}"
                else:
                    output = "\n".join(abs_matches)

                return True, output, None

            finally:
                os.chdir(original_cwd)

        except Exception as e:
            return False, "", f"Failed to glob files: {str(e)}"
