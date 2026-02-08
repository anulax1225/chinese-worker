"""Read tool for reading files."""

import os
from typing import Dict, Any, Tuple
from .base import BaseTool


class ReadTool(BaseTool):
    """Read file contents from the local filesystem."""

    @property
    def name(self) -> str:
        return "read"

    @property
    def description(self) -> str:
        return "Read the contents of a file"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "file_path": {
                    "type": "string",
                    "description": "Path to the file to read",
                },
                "offset": {
                    "type": "integer",
                    "description": "Line number to start reading from (0-indexed)",
                },
                "limit": {
                    "type": "integer",
                    "description": "Maximum number of lines to read",
                },
            },
            "required": ["file_path"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Read a file.

        Args:
            args: {"file_path": str, "offset": int (optional), "limit": int (optional)}

        Returns:
            Tuple of (success, output, error)
        """
        file_path = args.get("file_path")
        if not file_path:
            return False, "", "Missing 'file_path' argument"

        offset = args.get("offset", 0)
        limit = args.get("limit")

        # Make path absolute if relative
        if not os.path.isabs(file_path):
            file_path = os.path.join(os.getcwd(), file_path)

        try:
            if not os.path.exists(file_path):
                return False, "", f"File not found: {file_path}"

            if not os.path.isfile(file_path):
                return False, "", f"Path is not a file: {file_path}"

            with open(file_path, "r", encoding="utf-8", errors="replace") as f:
                lines = f.readlines()

            # Apply offset and limit
            if offset > 0:
                lines = lines[offset:]

            if limit is not None and limit > 0:
                lines = lines[:limit]

            # Format output with line numbers (starting from offset + 1)
            output_lines = []
            for i, line in enumerate(lines, start=offset + 1):
                # Truncate lines longer than 2000 characters
                if len(line) > 2000:
                    line = line[:2000] + "... [truncated]\n"
                output_lines.append(f"{i:6d}\t{line.rstrip()}")

            output = "\n".join(output_lines)
            return True, output, None

        except UnicodeDecodeError:
            return (
                False,
                "",
                f"File is not a text file or has encoding issues: {file_path}",
            )
        except Exception as e:
            return False, "", f"Failed to read file: {str(e)}"
