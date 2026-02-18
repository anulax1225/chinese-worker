"""Status badge widget for conversation status display."""

from textual.widgets import Static


class StatusBadge(Static):
    """Colored status indicator badge.

    Displays a single-character icon with appropriate color based on
    the conversation status.
    """

    STATUS_CONFIG = {
        "active": ("●", "$success"),
        "completed": ("✓", "$primary"),
        "failed": ("✗", "$error"),
        "cancelled": ("○", "$warning"),
        "paused": ("⏸", "$accent"),
    }

    DEFAULT_CSS = """
    StatusBadge {
        width: auto;
        height: 1;
    }

    StatusBadge.-status-active {
        color: $success;
    }

    StatusBadge.-status-completed {
        color: $primary;
    }

    StatusBadge.-status-failed {
        color: $error;
    }

    StatusBadge.-status-cancelled {
        color: $warning;
    }

    StatusBadge.-status-paused {
        color: $accent;
    }
    """

    def __init__(self, status: str = "active", **kwargs) -> None:
        super().__init__(**kwargs)
        self._status = status
        self.add_class("status-badge")
        self.add_class(f"-status-{status}")

    def render(self) -> str:
        icon, _ = self.STATUS_CONFIG.get(self._status, ("?", "$foreground"))
        return icon

    def set_status(self, status: str) -> None:
        """Update the badge status.

        Args:
            status: New status value (active, completed, failed, cancelled, paused)
        """
        self.remove_class(f"-status-{self._status}")
        self._status = status
        self.add_class(f"-status-{status}")
        self.refresh()

    @property
    def status(self) -> str:
        """Get the current status."""
        return self._status
