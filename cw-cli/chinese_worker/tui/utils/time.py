"""Time formatting utilities for TUI."""

from datetime import datetime, timezone
from typing import Optional


def relative_time(timestamp: Optional[str]) -> str:
    """Convert ISO timestamp to relative time string (e.g., '2h ago').

    Args:
        timestamp: ISO format timestamp string (may include Z or +00:00)

    Returns:
        Human-readable relative time like "just now", "5m ago", "2h ago", "3d ago"
    """
    if not timestamp:
        return "unknown"

    try:
        # Handle both Z and +00:00 timezone formats
        ts = timestamp.replace("Z", "+00:00")
        dt = datetime.fromisoformat(ts)

        # Ensure we're comparing UTC times
        if dt.tzinfo is None:
            dt = dt.replace(tzinfo=timezone.utc)

        now = datetime.now(timezone.utc)
        delta = now - dt

        seconds = int(delta.total_seconds())

        if seconds < 0:
            return "just now"
        elif seconds < 60:
            return "just now"
        elif seconds < 3600:
            mins = seconds // 60
            return f"{mins}m ago"
        elif seconds < 86400:
            hours = seconds // 3600
            return f"{hours}h ago"
        elif seconds < 604800:
            days = seconds // 86400
            return f"{days}d ago"
        else:
            weeks = seconds // 604800
            return f"{weeks}w ago"

    except (ValueError, TypeError, AttributeError):
        return "unknown"
