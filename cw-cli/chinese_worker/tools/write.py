"""Write tool for writing files."""

import os
from typing import Dict, Any, Tuple
from .base import BaseTool


class WriteTool(BaseTool):
    """Write content to files on the local filesystem."""

    @property
    def name(self) -> str:
        return "write"

    @property
    def description(self) -> str:
        return "Write content to a file"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "file_path": {
                    "type": "string",
                    "description": "Path to the file to write",
                },
                "content": {
                    "type": "string",
                    "description": "Content to write to the file",
                },
            },
            "required": ["file_path", "content"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Write content to a file.

        Args:
            args: {"file_path": str, "content": str}

        Returns:
            Tuple of (success, output, error)
        """
        file_path = args.get("file_path")
        content = args.get("content")

        if not file_path:
            return False, "", "Missing 'file_path' argument"

        if content is None:
            return False, "", "Missing 'content' argument"

        # Make path absolute if relative
        if not os.path.isabs(file_path):
            file_path = os.path.join(os.getcwd(), file_path)

        try:
            # Create parent directories if they don't exist
            os.makedirs(os.path.dirname(file_path), exist_ok=True)

            # Write the file
            with open(file_path, "w", encoding="utf-8") as f:
                f.write(content)

            output = f"Successfully wrote {len(content)} characters to {file_path}"
            return True, output, None

        except Exception as e:
            return False, "", f"Failed to write file: {str(e)}"
