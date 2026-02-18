"""TUI-specific test fixtures."""

import pytest

from textual.app import App, ComposeResult


class WidgetTestApp(App):
    """Minimal App for testing individual widgets in isolation."""

    CSS_PATH = []

    def __init__(self, widget_factory, **kwargs):
        super().__init__(**kwargs)
        self._widget_factory = widget_factory

    def compose(self) -> ComposeResult:
        yield self._widget_factory()


@pytest.fixture
def widget_app():
    """Factory fixture: returns a function that creates a WidgetTestApp."""

    def _make(widget_factory):
        return WidgetTestApp(widget_factory)

    return _make
