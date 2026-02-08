"""Grep tool for searching file contents."""

import os
import re
from typing import Dict, Any, Tuple, List
from pathlib import Path
from .base import BaseTool


class GrepTool(BaseTool):
    """Search for patterns in files using regex."""

    @property
    def name(self) -> str:
        return "grep"

    @property
    def description(self) -> str:
        return "Search for a pattern in files using regex"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "pattern": {
                    "type": "string",
                    "description": "Regex pattern to search for",
                },
                "path": {
                    "type": "string",
                    "description": "Path to search in (default: current directory)",
                },
                "glob": {
                    "type": "string",
                    "description": "Glob pattern to filter files (e.g., '*.py')",
                },
                "type": {
                    "type": "string",
                    "description": "File type filter (e.g., 'py', 'js', 'php')",
                },
                "output_mode": {
                    "type": "string",
                    "description": "Output mode: 'content', 'files_with_matches', or 'count'",
                    "enum": ["content", "files_with_matches", "count"],
                },
                "case_insensitive": {
                    "type": "boolean",
                    "description": "Case insensitive search (default: false)",
                },
                "head_limit": {
                    "type": "integer",
                    "description": "Limit output to N results",
                },
                "context_before": {
                    "type": "integer",
                    "description": "Number of lines to show before each match",
                },
                "context_after": {
                    "type": "integer",
                    "description": "Number of lines to show after each match",
                },
            },
            "required": ["pattern"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Search for a pattern in files.

        Args:
            args: {
                "pattern": str (regex pattern),
                "path": str (optional, default cwd),
                "glob": str (optional glob pattern to filter files),
                "type": str (optional file type filter),
                "output_mode": str (optional: "content", "files_with_matches", "count"),
                "case_insensitive": bool (optional, default False),
                "head_limit": int (optional, limit output lines),
                "context_before": int (optional, lines before match),
                "context_after": int (optional, lines after match),
            }

        Returns:
            Tuple of (success, output, error)
        """
        pattern_str = args.get("pattern")
        if not pattern_str:
            return False, "", "Missing 'pattern' argument"

        search_path = args.get("path", os.getcwd())
        glob_pattern = args.get("glob")
        file_type = args.get("type")
        output_mode = args.get("output_mode", "files_with_matches")
        case_insensitive = args.get("case_insensitive", False)
        head_limit = args.get("head_limit", 0)
        context_before = args.get("context_before", 0)
        context_after = args.get("context_after", 0)

        # Make path absolute if relative
        if not os.path.isabs(search_path):
            search_path = os.path.join(os.getcwd(), search_path)

        try:
            # Compile regex pattern
            flags = re.IGNORECASE if case_insensitive else 0
            regex = re.compile(pattern_str, flags)

            # Find files to search
            files_to_search = self._find_files(search_path, glob_pattern, file_type)

            if not files_to_search:
                return True, "No files found to search", None

            # Search files
            results = []
            for file_path in files_to_search:
                try:
                    file_results = self._search_file(
                        file_path, regex, output_mode, context_before, context_after
                    )
                    if file_results:
                        results.extend(file_results)

                        # Apply head limit if set
                        if head_limit > 0 and len(results) >= head_limit:
                            results = results[:head_limit]
                            break
                except Exception:
                    # Skip files that can't be read
                    continue

            if not results:
                return True, "No matches found", None

            output = "\n".join(results)
            return True, output, None

        except re.error as e:
            return False, "", f"Invalid regex pattern: {str(e)}"
        except Exception as e:
            return False, "", f"Failed to grep: {str(e)}"

    def _find_files(
        self, search_path: str, glob_pattern: str = None, file_type: str = None
    ) -> List[str]:
        """Find files to search based on glob pattern or file type."""
        files = []

        if os.path.isfile(search_path):
            return [search_path]

        # Walk directory tree
        for root, dirs, filenames in os.walk(search_path):
            # Skip hidden directories
            dirs[:] = [d for d in dirs if not d.startswith(".")]

            for filename in filenames:
                file_path = os.path.join(root, filename)

                # Apply glob filter
                if glob_pattern:
                    rel_path = os.path.relpath(file_path, search_path)
                    if not self._match_glob(rel_path, glob_pattern):
                        continue

                # Apply file type filter
                if file_type and not self._match_type(filename, file_type):
                    continue

                files.append(file_path)

        return files

    def _match_glob(self, path: str, pattern: str) -> bool:
        """Simple glob pattern matching."""
        import fnmatch

        return fnmatch.fnmatch(path, pattern)

    def _match_type(self, filename: str, file_type: str) -> bool:
        """Match file by type."""
        type_extensions = {
            "py": [".py"],
            "js": [".js", ".jsx"],
            "ts": [".ts", ".tsx"],
            "php": [".php"],
            "java": [".java"],
            "go": [".go"],
            "rust": [".rs"],
            "c": [".c", ".h"],
            "cpp": [".cpp", ".hpp", ".cc", ".cxx"],
            "md": [".md", ".markdown"],
            "json": [".json"],
            "yaml": [".yaml", ".yml"],
            "xml": [".xml"],
            "html": [".html", ".htm"],
            "css": [".css"],
        }

        extensions = type_extensions.get(file_type, [])
        return any(filename.endswith(ext) for ext in extensions)

    def _search_file(
        self,
        file_path: str,
        regex: re.Pattern,
        output_mode: str,
        context_before: int,
        context_after: int,
    ) -> List[str]:
        """Search a single file for pattern matches."""
        results = []

        try:
            with open(file_path, "r", encoding="utf-8", errors="replace") as f:
                lines = f.readlines()

            matches = []
            for i, line in enumerate(lines, start=1):
                if regex.search(line):
                    matches.append(i)

            if not matches:
                return []

            if output_mode == "files_with_matches":
                return [file_path]

            elif output_mode == "count":
                return [f"{file_path}: {len(matches)} matches"]

            elif output_mode == "content":
                # Show matching lines with optional context
                shown_lines = set()

                for line_num in matches:
                    # Add context lines
                    start = max(1, line_num - context_before)
                    end = min(len(lines), line_num + context_after)

                    for i in range(start, end + 1):
                        if i not in shown_lines:
                            shown_lines.add(i)
                            prefix = f"{file_path}:{i}: "
                            results.append(f"{prefix}{lines[i - 1].rstrip()}")

                return results

        except (UnicodeDecodeError, PermissionError):
            # Skip binary files or files we can't read
            return []

        return results
