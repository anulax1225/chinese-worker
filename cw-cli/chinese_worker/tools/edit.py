"""Edit tool for performing string replacements in files."""

import os
from typing import Dict, Any, Tuple
from .base import BaseTool


class EditTool(BaseTool):
    """Perform exact string replacements in files."""

    @property
    def name(self) -> str:
        return "edit"

    @property
    def description(self) -> str:
        return "Edit a file by replacing old text with new text"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "file_path": {
                    "type": "string",
                    "description": "Path to the file to edit",
                },
                "old_string": {
                    "type": "string",
                    "description": "The text to find and replace",
                },
                "new_string": {
                    "type": "string",
                    "description": "The text to replace with",
                },
                "replace_all": {
                    "type": "boolean",
                    "description": "Replace all occurrences (default: false)",
                },
            },
            "required": ["file_path", "old_string", "new_string"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Edit a file by replacing old_string with new_string.

        Args:
            args: {
                "file_path": str,
                "old_string": str,
                "new_string": str,
                "replace_all": bool (optional, default False)
            }

        Returns:
            Tuple of (success, output, error)
        """
        file_path = args.get("file_path")
        old_string = args.get("old_string")
        new_string = args.get("new_string")
        replace_all = args.get("replace_all", False)

        if not file_path:
            return False, "", "Missing 'file_path' argument"

        if old_string is None:
            return False, "", "Missing 'old_string' argument"

        if new_string is None:
            return False, "", "Missing 'new_string' argument"

        if old_string == new_string:
            return False, "", "old_string and new_string must be different"

        # Make path absolute if relative
        if not os.path.isabs(file_path):
            file_path = os.path.join(os.getcwd(), file_path)

        try:
            if not os.path.exists(file_path):
                return False, "", f"File not found: {file_path}"

            if not os.path.isfile(file_path):
                return False, "", f"Path is not a file: {file_path}"

            # Read the file
            with open(file_path, "r", encoding="utf-8") as f:
                content = f.read()

            # Count occurrences
            count = content.count(old_string)

            if count == 0:
                return False, "", f"String not found in file: {old_string[:50]}..."

            if not replace_all and count > 1:
                return (
                    False,
                    "",
                    f"String appears {count} times. Use replace_all=true to replace all occurrences",
                )

            # Perform replacement
            if replace_all:
                new_content = content.replace(old_string, new_string)
                occurrences = count
            else:
                new_content = content.replace(old_string, new_string, 1)
                occurrences = 1

            # Write the file
            with open(file_path, "w", encoding="utf-8") as f:
                f.write(new_content)

            output = f"Successfully replaced {occurrences} occurrence(s) in {file_path}"
            return True, output, None

        except Exception as e:
            return False, "", f"Failed to edit file: {str(e)}"
