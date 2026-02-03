"""Authentication manager using plain file for token storage."""

import json
import os
import platform
from pathlib import Path
from typing import Optional


def _get_config_dir() -> Path:
    """Get platform-appropriate config directory."""
    system = platform.system().lower()
    if system == "windows":
        base = Path(os.getenv("APPDATA", os.path.expanduser("~")))
        return base / "chinese-worker"
    elif system == "darwin":
        return Path.home() / "Library" / "Application Support" / "chinese-worker"
    else:
        return Path.home() / ".cw"


def _get_token_file() -> Path:
    """Get platform-appropriate token file path."""
    config_dir = _get_config_dir()
    config_dir.mkdir(parents=True, exist_ok=True)
    return config_dir / "token.json"


class AuthManager:
    """Manages authentication tokens using a plain JSON file."""

    @classmethod
    def _token_file(cls) -> Path:
        """Get the token file path (lazy initialization)."""
        return _get_token_file()

    @classmethod
    def get_token(cls) -> Optional[str]:
        """Retrieve stored authentication token from file."""
        token_file = cls._token_file()
        if token_file.exists():
            try:
                with open(token_file, "r") as f:
                    data = json.load(f)
                    return data.get("token")
            except (json.JSONDecodeError, KeyError):
                return None
        return None

    @classmethod
    def set_token(cls, token: str) -> None:
        """Store authentication token in file."""
        token_file = cls._token_file()
        token_file.parent.mkdir(parents=True, exist_ok=True)
        with open(token_file, "w") as f:
            json.dump({"token": token}, f)

    @classmethod
    def clear_token(cls) -> None:
        """Remove stored authentication token file."""
        token_file = cls._token_file()
        if token_file.exists():
            token_file.unlink()

    @classmethod
    def is_authenticated(cls) -> bool:
        """Check if user has stored authentication token."""
        return cls.get_token() is not None

