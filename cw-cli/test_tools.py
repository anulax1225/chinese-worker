#!/usr/bin/env python3
"""Test script for builtin tools."""

import os
import tempfile
from chinese_worker.tools import BashTool, ReadTool, WriteTool, EditTool, GlobTool, GrepTool


def test_bash():
    """Test bash tool."""
    print("\n=== Testing Bash Tool ===")
    tool = BashTool()

    # Test simple command
    success, output, error = tool.execute({"command": "echo 'Hello World'"})
    print(f"✓ Echo test: {success}")
    print(f"  Output: {output}")

    # Test ls
    success, output, error = tool.execute({"command": "ls -la | head -5"})
    print(f"✓ ls test: {success}")
    print(f"  Output lines: {len(output.splitlines())}")


def test_read_write():
    """Test read and write tools."""
    print("\n=== Testing Read/Write Tools ===")

    with tempfile.TemporaryDirectory() as tmpdir:
        test_file = os.path.join(tmpdir, "test.txt")

        # Write
        write_tool = WriteTool()
        content = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5"
        success, output, error = write_tool.execute({
            "file_path": test_file,
            "content": content
        })
        print(f"✓ Write test: {success}")
        print(f"  Output: {output}")

        # Read
        read_tool = ReadTool()
        success, output, error = read_tool.execute({"file_path": test_file})
        print(f"✓ Read test: {success}")
        print(f"  Lines read: {len(output.splitlines())}")

        # Read with offset and limit
        success, output, error = read_tool.execute({
            "file_path": test_file,
            "offset": 1,
            "limit": 2
        })
        print(f"✓ Read with offset/limit: {success}")
        print(f"  Lines: {len(output.splitlines())}")


def test_edit():
    """Test edit tool."""
    print("\n=== Testing Edit Tool ===")

    with tempfile.TemporaryDirectory() as tmpdir:
        test_file = os.path.join(tmpdir, "test.txt")

        # Create file
        with open(test_file, "w") as f:
            f.write("Hello World\nFoo Bar\nHello World")

        # Edit
        edit_tool = EditTool()
        success, output, error = edit_tool.execute({
            "file_path": test_file,
            "old_string": "Hello World",
            "new_string": "Hi Universe"
        })

        if success:
            print(f"✓ Edit test (single replace): {success}")
            print(f"  Output: {output}")
        else:
            print(f"✗ Edit test failed: {error}")
            # Try with replace_all
            success, output, error = edit_tool.execute({
                "file_path": test_file,
                "old_string": "Hello World",
                "new_string": "Hi Universe",
                "replace_all": True
            })
            print(f"✓ Edit test (replace all): {success}")
            print(f"  Output: {output}")


def test_glob():
    """Test glob tool."""
    print("\n=== Testing Glob Tool ===")

    glob_tool = GlobTool()

    # Test finding Python files
    success, output, error = glob_tool.execute({
        "pattern": "*.py",
        "path": "."
    })
    print(f"✓ Glob test: {success}")
    files = output.split("\n") if output else []
    print(f"  Found {len(files)} Python files")


def test_grep():
    """Test grep tool."""
    print("\n=== Testing Grep Tool ===")

    with tempfile.TemporaryDirectory() as tmpdir:
        # Create test files
        test_file1 = os.path.join(tmpdir, "test1.txt")
        test_file2 = os.path.join(tmpdir, "test2.txt")

        with open(test_file1, "w") as f:
            f.write("Hello World\nFoo Bar\nHello Universe")

        with open(test_file2, "w") as f:
            f.write("Some text\nHello Python\nMore text")

        # Grep
        grep_tool = GrepTool()

        # Files with matches
        success, output, error = grep_tool.execute({
            "pattern": "Hello",
            "path": tmpdir,
            "output_mode": "files_with_matches"
        })
        print(f"✓ Grep test (files): {success}")
        print(f"  Output: {output}")

        # Content mode
        success, output, error = grep_tool.execute({
            "pattern": "Hello",
            "path": tmpdir,
            "output_mode": "content"
        })
        print(f"✓ Grep test (content): {success}")
        print(f"  Lines: {len(output.splitlines())}")


if __name__ == "__main__":
    print("Testing Chinese Worker Builtin Tools")
    print("=" * 50)

    test_bash()
    test_read_write()
    test_edit()
    test_glob()
    test_grep()

    print("\n" + "=" * 50)
    print("All tests completed!")
