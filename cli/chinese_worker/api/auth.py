"""Authentication manager using plain file for token storage."""

import json
import os
from typing import Optional
from pathlib import Path

class AuthManager:
    """Manages authentication tokens using a plain JSON file."""
    
    # Define the token file location (in user's home directory)
    TOKEN_FILE = Path.home() / ".chinese-worker-cli-token.json"
    
    @classmethod
    def get_token(cls) -> Optional[str]:
        """Retrieve stored authentication token from file."""
        if cls.TOKEN_FILE.exists():
            try:
                with open(cls.TOKEN_FILE, 'r') as f:
                    data = json.load(f)
                    return data.get('token')
            except (json.JSONDecodeError, KeyError):
                return None
        return None
    
    @classmethod
    def set_token(cls, token: str) -> None:
        """Store authentication token in file."""
        cls.TOKEN_FILE.parent.mkdir(exist_ok=True)
        with open(cls.TOKEN_FILE, 'w') as f:
            json.dump({'token': token}, f)
    
    @classmethod
    def clear_token(cls) -> None:
        """Remove stored authentication token file."""
        if cls.TOKEN_FILE.exists():
            cls.TOKEN_FILE.unlink()
    
    @classmethod
    def is_authenticated(cls) -> bool:
        """Check if user has stored authentication token."""
        return cls.get_token() is not None

