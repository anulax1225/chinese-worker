"""HTTP client for Chinese Worker API."""

import httpx
from typing import Optional, Dict, Any, List
from .auth import AuthManager


class APIClient:
    """HTTP client for communicating with Chinese Worker backend."""

    def __init__(self, base_url: str, timeout: int = 5 * 60 * 60 * 60):
        """
        Initialize API client.

        Args:
            base_url: Base URL of the API (e.g., http://localhost:8000)
            timeout: Request timeout in seconds
        """
        self.base_url = base_url.rstrip("/")
        self.timeout = timeout
        self.auth = AuthManager()

    def _get_headers(self) -> Dict[str, str]:
        """Get headers for authenticated requests."""
        headers = {
            "Accept": "application/json",
            "Content-Type": "application/json",
        }

        token = self.auth.get_token()
        if token:
            headers["Authorization"] = f"Bearer {token}"

        return headers

    def login(self, email: str, password: str) -> Dict[str, Any]:
        """
        Authenticate user and store token.

        Args:
            email: User email
            password: User password

        Returns:
            User data and token information

        Raises:
            httpx.HTTPStatusError: If authentication fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/auth/login",
            json={"email": email, "password": password},
            timeout=self.timeout,                  
        )
        response.raise_for_status()

        data = response.json()
        if "token" in data:
            self.auth.set_token(data["token"])

        return data

    def logout(self) -> None:
        """Logout and remove stored token."""
        if self.auth.is_authenticated():
            try:
                httpx.post(
                    f"{self.base_url}/api/v1/auth/logout",
                    headers=self._get_headers(),
                    timeout=self.timeout,
                )
            except httpx.HTTPError:
                pass  # Ignore errors during logout

        self.auth.clear_token()

    def get_user(self) -> Dict[str, Any]:
        """
        Get current authenticated user.

        Returns:
            User data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/auth/user",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def list_agents(self) -> List[Dict[str, Any]]:
        """
        List user's agents.

        Returns:
            List of agent data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/agents",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_agent(self, agent_id: int) -> Dict[str, Any]:
        """
        Get agent by ID.

        Args:
            agent_id: Agent ID

        Returns:
            Agent data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/agents/{agent_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def create_conversation(
        self,
        agent_id: int,
        metadata: Optional[Dict[str, Any]] = None,
        client_type: Optional[str] = None,
        client_tool_schemas: Optional[List[Dict[str, Any]]] = None,
    ) -> Dict[str, Any]:
        """
        Create a new conversation with an agent.

        Args:
            agent_id: Agent ID
            metadata: Optional metadata
            client_type: Type of client (e.g., 'cli_linux', 'cli_windows', 'web')
            client_tool_schemas: List of tool schemas the client supports

        Returns:
            Conversation data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        payload = {}
        if metadata:
            payload["metadata"] = metadata
        if client_type:
            payload["client_type"] = client_type
        if client_tool_schemas:
            payload["client_tool_schemas"] = client_tool_schemas

        response = httpx.post(
            f"{self.base_url}/api/v1/agents/{agent_id}/conversations",
            json=payload,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def send_message(
        self, conversation_id: int, content: str, images: Optional[List[str]] = None
    ) -> Dict[str, Any]:
        """
        Send a message to a conversation.

        Args:
            conversation_id: Conversation ID
            content: Message content
            images: Optional list of base64-encoded images

        Returns:
            Response with status and tool requests if any

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        payload = {"content": content}
        if images:
            payload["images"] = images

        response = httpx.post(
            f"{self.base_url}/api/v1/conversations/{conversation_id}/messages",
            json=payload,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def get_status(self, conversation_id: int) -> Dict[str, Any]:
        """
        Poll conversation status.

        Args:
            conversation_id: Conversation ID

        Returns:
            Status response with tool requests if any

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/conversations/{conversation_id}/status",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def submit_tool_result(
        self,
        conversation_id: int,
        call_id: str,
        success: bool,
        output: Optional[str] = None,
        error: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Submit tool execution result back to server.

        Args:
            conversation_id: Conversation ID
            call_id: Tool call ID
            success: Whether tool executed successfully
            output: Tool output if successful
            error: Error message if failed

        Returns:
            Status response

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        payload = {
            "call_id": call_id,
            "success": success,
            "output": output,
            "error": error,
        }

        response = httpx.post(
            f"{self.base_url}/api/v1/conversations/{conversation_id}/tool-results",
            json=payload,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def get_conversation(self, conversation_id: int) -> Dict[str, Any]:
        """
        Get full conversation details.

        Args:
            conversation_id: Conversation ID

        Returns:
            Conversation data with messages

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/conversations/{conversation_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def list_conversations(
        self,
        agent_id: Optional[int] = None,
        status: Optional[str] = None,
        per_page: int = 15,
    ) -> List[Dict[str, Any]]:
        """
        List user's conversations.

        Args:
            agent_id: Filter by agent ID
            status: Filter by status
            per_page: Results per page

        Returns:
            List of conversation data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        params = {"per_page": per_page}
        if agent_id:
            params["agent_id"] = agent_id
        if status:
            params["status"] = status

        response = httpx.get(
            f"{self.base_url}/api/v1/conversations",
            params=params,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def delete_conversation(self, conversation_id: int) -> None:
        """
        Delete a conversation.

        Args:
            conversation_id: Conversation ID

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/conversations/{conversation_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()

    def stop_conversation(self, conversation_id: int) -> Dict[str, Any]:
        """
        Stop a running conversation.

        Args:
            conversation_id: Conversation ID

        Returns:
            Status response with cancelled state

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/conversations/{conversation_id}/stop",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()
