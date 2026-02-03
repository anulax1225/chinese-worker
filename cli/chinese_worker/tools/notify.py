"""Notification tool for cross-platform desktop notifications."""

import platform
import subprocess
from typing import Any, Dict, Tuple

from .base import BaseTool


class NotifyTool(BaseTool):
    """Send desktop notifications."""

    @property
    def name(self) -> str:
        return "notify"

    @property
    def description(self) -> str:
        return "Send a desktop notification with a title and message"

    @property
    def parameters(self) -> Dict[str, Any]:
        return {
            "type": "object",
            "properties": {
                "title": {
                    "type": "string",
                    "description": "Notification title",
                },
                "message": {
                    "type": "string",
                    "description": "Notification message body",
                },
                "sound": {
                    "type": "boolean",
                    "description": "Play notification sound (default: true, macOS/Windows only)",
                },
            },
            "required": ["title", "message"],
        }

    def execute(self, args: Dict[str, Any]) -> Tuple[bool, str, str]:
        """
        Send a desktop notification.

        Args:
            args: {"title": str, "message": str, "sound": bool (optional)}

        Returns:
            Tuple of (success, output, error)
        """
        title = args.get("title")
        message = args.get("message")
        sound = args.get("sound", True)

        if not title:
            return False, "", "Missing 'title' argument"
        if not message:
            return False, "", "Missing 'message' argument"

        system = platform.system().lower()

        try:
            if system == "darwin":
                return self._notify_macos(title, message, sound)
            elif system == "windows":
                return self._notify_windows(title, message)
            else:
                return self._notify_linux(title, message)
        except FileNotFoundError as e:
            return False, "", f"Notification command not found: {str(e)}"
        except Exception as e:
            return False, "", f"Failed to send notification: {str(e)}"

    def _notify_macos(self, title: str, message: str, sound: bool) -> Tuple[bool, str, str]:
        """Send notification on macOS using osascript."""
        # Escape quotes for AppleScript
        title_escaped = title.replace('"', '\\"')
        message_escaped = message.replace('"', '\\"')

        script = f'display notification "{message_escaped}" with title "{title_escaped}"'
        if sound:
            script += ' sound name "default"'

        result = subprocess.run(
            ["osascript", "-e", script],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, f"Notification sent: {title}", None
        else:
            return False, "", f"Failed: {result.stderr}"

    def _notify_windows(self, title: str, message: str) -> Tuple[bool, str, str]:
        """Send notification on Windows using PowerShell toast."""
        # PowerShell script for toast notification
        ps_script = f'''
        [Windows.UI.Notifications.ToastNotificationManager, Windows.UI.Notifications, ContentType = WindowsRuntime] | Out-Null
        [Windows.Data.Xml.Dom.XmlDocument, Windows.Data.Xml.Dom.XmlDocument, ContentType = WindowsRuntime] | Out-Null

        $template = @"
        <toast>
            <visual>
                <binding template="ToastText02">
                    <text id="1">{title}</text>
                    <text id="2">{message}</text>
                </binding>
            </visual>
        </toast>
"@

        $xml = New-Object Windows.Data.Xml.Dom.XmlDocument
        $xml.LoadXml($template)
        $toast = [Windows.UI.Notifications.ToastNotification]::new($xml)
        $notifier = [Windows.UI.Notifications.ToastNotificationManager]::CreateToastNotifier("Chinese Worker")
        $notifier.Show($toast)
        '''

        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command", ps_script],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, f"Notification sent: {title}", None
        else:
            # Fall back to simpler BurntToast or msg approach if toast API fails
            return self._notify_windows_fallback(title, message)

    def _notify_windows_fallback(self, title: str, message: str) -> Tuple[bool, str, str]:
        """Fallback Windows notification using msg command."""
        # Simple fallback - this shows a message box
        result = subprocess.run(
            ["powershell", "-NoProfile", "-Command",
             f"Add-Type -AssemblyName System.Windows.Forms; "
             f"[System.Windows.Forms.MessageBox]::Show('{message}', '{title}')"],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, f"Notification sent: {title}", None
        else:
            return False, "", f"Failed: {result.stderr}"

    def _notify_linux(self, title: str, message: str) -> Tuple[bool, str, str]:
        """Send notification on Linux using notify-send."""
        result = subprocess.run(
            ["notify-send", title, message],
            capture_output=True,
            text=True,
        )

        if result.returncode == 0:
            return True, f"Notification sent: {title}", None
        else:
            return False, "", f"Failed: {result.stderr}"
