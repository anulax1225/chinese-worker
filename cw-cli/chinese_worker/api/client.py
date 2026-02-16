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

    # ==================== Agent Management ====================

    def create_agent(self, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Create a new agent.

        Args:
            data: Agent data (name, description, ai_backend, model, etc.)

        Returns:
            Created agent data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/agents",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def update_agent(self, agent_id: int, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Update an agent.

        Args:
            agent_id: Agent ID
            data: Updated agent data

        Returns:
            Updated agent data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.put(
            f"{self.base_url}/api/v1/agents/{agent_id}",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def delete_agent(self, agent_id: int) -> None:
        """
        Delete an agent.

        Args:
            agent_id: Agent ID

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/agents/{agent_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()

    def attach_tools(self, agent_id: int, tool_ids: List[int]) -> Dict[str, Any]:
        """
        Attach tools to an agent.

        Args:
            agent_id: Agent ID
            tool_ids: List of tool IDs to attach

        Returns:
            Response data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/agents/{agent_id}/tools",
            json={"tool_ids": tool_ids},
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def detach_tool(self, agent_id: int, tool_id: int) -> Dict[str, Any]:
        """
        Detach a tool from an agent.

        Args:
            agent_id: Agent ID
            tool_id: Tool ID to detach

        Returns:
            Response data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/agents/{agent_id}/tools/{tool_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    # ==================== Tool Management ====================

    def list_tools(
        self,
        include_builtin: bool = True,
        type_filter: Optional[str] = None,
        search: Optional[str] = None,
    ) -> List[Dict[str, Any]]:
        """
        List tools.

        Args:
            include_builtin: Include built-in tools
            type_filter: Filter by tool type (api, function, command, builtin)
            search: Search query

        Returns:
            List of tool data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        params: Dict[str, Any] = {"include_builtin": include_builtin}
        if type_filter:
            params["type"] = type_filter
        if search:
            params["search"] = search

        response = httpx.get(
            f"{self.base_url}/api/v1/tools",
            params=params,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_tool(self, tool_id: int) -> Dict[str, Any]:
        """
        Get tool by ID.

        Args:
            tool_id: Tool ID

        Returns:
            Tool data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/tools/{tool_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def create_tool(self, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Create a new tool.

        Args:
            data: Tool data (name, type, description, configuration)

        Returns:
            Created tool data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/tools",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def update_tool(self, tool_id: int, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Update a tool.

        Args:
            tool_id: Tool ID
            data: Updated tool data

        Returns:
            Updated tool data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.put(
            f"{self.base_url}/api/v1/tools/{tool_id}",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def delete_tool(self, tool_id: int) -> None:
        """
        Delete a tool.

        Args:
            tool_id: Tool ID

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/tools/{tool_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()

    # ==================== System Prompt Management ====================

    def list_system_prompts(
        self,
        search: Optional[str] = None,
        active: Optional[bool] = None,
    ) -> List[Dict[str, Any]]:
        """
        List system prompts.

        Args:
            search: Search query
            active: Filter by active status

        Returns:
            List of system prompt data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        params: Dict[str, Any] = {}
        if search:
            params["search"] = search
        if active is not None:
            params["active"] = active

        response = httpx.get(
            f"{self.base_url}/api/v1/system-prompts",
            params=params,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_system_prompt(self, prompt_id: int) -> Dict[str, Any]:
        """
        Get system prompt by ID.

        Args:
            prompt_id: System prompt ID

        Returns:
            System prompt data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/system-prompts/{prompt_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def create_system_prompt(self, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Create a new system prompt.

        Args:
            data: System prompt data (name, template, variables, is_active)

        Returns:
            Created system prompt data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/system-prompts",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def update_system_prompt(self, prompt_id: int, data: Dict[str, Any]) -> Dict[str, Any]:
        """
        Update a system prompt.

        Args:
            prompt_id: System prompt ID
            data: Updated system prompt data

        Returns:
            Updated system prompt data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.put(
            f"{self.base_url}/api/v1/system-prompts/{prompt_id}",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def delete_system_prompt(self, prompt_id: int) -> None:
        """
        Delete a system prompt.

        Args:
            prompt_id: System prompt ID

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/system-prompts/{prompt_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()

    # ==================== Document Management ====================

    def list_documents(
        self,
        status: Optional[str] = None,
        search: Optional[str] = None,
    ) -> List[Dict[str, Any]]:
        """
        List documents.

        Args:
            status: Filter by status (pending, extracting, cleaning, normalizing, chunking, ready, failed)
            search: Search query

        Returns:
            List of document data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        params: Dict[str, Any] = {}
        if status:
            params["status"] = status
        if search:
            params["search"] = search

        response = httpx.get(
            f"{self.base_url}/api/v1/documents",
            params=params,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_document(self, doc_id: int) -> Dict[str, Any]:
        """
        Get document by ID.

        Args:
            doc_id: Document ID

        Returns:
            Document data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/documents/{doc_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def upload_document(
        self,
        file_path: str,
        title: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Upload a document file.

        Args:
            file_path: Path to the file to upload
            title: Optional title for the document

        Returns:
            Created document data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        import os

        with open(file_path, "rb") as f:
            files = {"file": (os.path.basename(file_path), f)}
            data = {}
            if title:
                data["title"] = title

            # Need different headers for multipart
            headers = {}
            token = self.auth.get_token()
            if token:
                headers["Authorization"] = f"Bearer {token}"
            headers["Accept"] = "application/json"

            response = httpx.post(
                f"{self.base_url}/api/v1/documents",
                files=files,
                data=data,
                headers=headers,
                timeout=self.timeout,
            )
        response.raise_for_status()
        return response.json()

    def upload_document_from_url(
        self,
        url: str,
        title: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Ingest a document from a URL.

        Args:
            url: URL to fetch document from
            title: Optional title for the document

        Returns:
            Created document data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        data: Dict[str, Any] = {"url": url, "source_type": "url"}
        if title:
            data["title"] = title

        response = httpx.post(
            f"{self.base_url}/api/v1/documents",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def upload_document_from_text(
        self,
        text: str,
        title: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Create a document from pasted text.

        Args:
            text: Text content
            title: Optional title for the document

        Returns:
            Created document data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        data: Dict[str, Any] = {"content": text, "source_type": "paste"}
        if title:
            data["title"] = title

        response = httpx.post(
            f"{self.base_url}/api/v1/documents",
            json=data,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def get_document_stages(self, doc_id: int) -> List[Dict[str, Any]]:
        """
        Get document processing stages.

        Args:
            doc_id: Document ID

        Returns:
            List of processing stage data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/documents/{doc_id}/stages",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_document_chunks(
        self,
        doc_id: int,
        per_page: int = 50,
    ) -> List[Dict[str, Any]]:
        """
        Get document chunks.

        Args:
            doc_id: Document ID
            per_page: Number of chunks per page

        Returns:
            List of chunk data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/documents/{doc_id}/chunks",
            params={"per_page": per_page},
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_document_preview(self, doc_id: int) -> Dict[str, Any]:
        """
        Get document preview comparison.

        Args:
            doc_id: Document ID

        Returns:
            Preview data with original and processed content

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/documents/{doc_id}/preview",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def reprocess_document(self, doc_id: int) -> Dict[str, Any]:
        """
        Reprocess a document.

        Args:
            doc_id: Document ID

        Returns:
            Document data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/documents/{doc_id}/reprocess",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def delete_document(self, doc_id: int) -> None:
        """
        Delete a document.

        Args:
            doc_id: Document ID

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/documents/{doc_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()

    def get_supported_document_types(self) -> List[str]:
        """
        Get supported document MIME types.

        Returns:
            List of supported MIME types

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/documents/supported-types",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    # ==================== AI Backend Management ====================

    def list_backends(self) -> Dict[str, Any]:
        """
        List AI backends with status.

        Returns:
            Dictionary of backend data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/ai-backends",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def list_backend_models(
        self,
        backend: str,
        detailed: bool = False,
    ) -> List[Dict[str, Any]]:
        """
        List models for a backend.

        Args:
            backend: Backend name (ollama, anthropic, openai)
            detailed: Include detailed model info

        Returns:
            List of model data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        params: Dict[str, Any] = {}
        if detailed:
            params["detailed"] = True

        response = httpx.get(
            f"{self.base_url}/api/v1/ai-backends/{backend}/models",
            params=params,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def pull_model(self, backend: str, model: str) -> Dict[str, Any]:
        """
        Start pulling a model.

        Args:
            backend: Backend name
            model: Model name to pull

        Returns:
            Response with pull_id for streaming progress

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.post(
            f"{self.base_url}/api/v1/ai-backends/{backend}/models/pull",
            json={"model": model},
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def get_model_info(self, backend: str, model: str) -> Dict[str, Any]:
        """
        Get detailed model information.

        Args:
            backend: Backend name
            model: Model name

        Returns:
            Model details

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/ai-backends/{backend}/models/{model}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def delete_model(self, backend: str, model: str) -> Dict[str, Any]:
        """
        Delete a model.

        Args:
            backend: Backend name
            model: Model name

        Returns:
            Response data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/ai-backends/{backend}/models/{model}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    # ==================== File Management ====================

    def list_files(
        self,
        type_filter: Optional[str] = None,
    ) -> List[Dict[str, Any]]:
        """
        List files.

        Args:
            type_filter: Filter by file type (input, output, temp)

        Returns:
            List of file data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        params: Dict[str, Any] = {}
        if type_filter:
            params["type"] = type_filter

        response = httpx.get(
            f"{self.base_url}/api/v1/files",
            params=params,
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()["data"]

    def get_file(self, file_id: int) -> Dict[str, Any]:
        """
        Get file by ID.

        Args:
            file_id: File ID

        Returns:
            File data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/files/{file_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.json()

    def upload_file(
        self,
        file_path: str,
        file_type: str = "input",
    ) -> Dict[str, Any]:
        """
        Upload a file.

        Args:
            file_path: Path to the file to upload
            file_type: Type of file (input, output, temp)

        Returns:
            Created file data

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        import os

        with open(file_path, "rb") as f:
            files = {"file": (os.path.basename(file_path), f)}
            data = {"type": file_type}

            # Need different headers for multipart
            headers = {}
            token = self.auth.get_token()
            if token:
                headers["Authorization"] = f"Bearer {token}"
            headers["Accept"] = "application/json"

            response = httpx.post(
                f"{self.base_url}/api/v1/files",
                files=files,
                data=data,
                headers=headers,
                timeout=self.timeout,
            )
        response.raise_for_status()
        return response.json()

    def download_file(self, file_id: int) -> bytes:
        """
        Download a file.

        Args:
            file_id: File ID

        Returns:
            File content as bytes

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.get(
            f"{self.base_url}/api/v1/files/{file_id}/download",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
        return response.content

    def delete_file(self, file_id: int) -> None:
        """
        Delete a file.

        Args:
            file_id: File ID

        Raises:
            httpx.HTTPStatusError: If request fails
        """
        response = httpx.delete(
            f"{self.base_url}/api/v1/files/{file_id}",
            headers=self._get_headers(),
            timeout=self.timeout,
        )
        response.raise_for_status()
