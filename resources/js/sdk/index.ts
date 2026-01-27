/**
 * Chinese Worker API SDK
 *
 * A comprehensive JavaScript SDK for the Chinese Worker API.
 * Provides type-safe access to all API endpoints with built-in authentication handling.
 *
 * @example
 * ```typescript
 * import { api, login, listAgents, executeAgent, streamAgent } from '@/sdk';
 *
 * // Login and store token
 * const { user, token } = await login({ email: 'user@example.com', password: 'password' });
 *
 * // List agents
 * const agents = await listAgents({ status: 'active' });
 *
 * // Execute an agent
 * const execution = await executeAgent(1, { payload: { task: 'Generate code' } });
 *
 * // Stream an agent execution
 * const stream = streamAgent(1, { payload: { task: 'Generate code' } }, {
 *     onChunk: (content) => console.log(content),
 *     onDone: (id, status) => console.log(`Done: ${id} - ${status}`),
 *     onError: (message) => console.error(message),
 * });
 *
 * // Close stream when needed
 * stream.close();
 * ```
 */

// ============================================================================
// Client Configuration
// ============================================================================

export {
    HttpClient,
    getClient,
    configureClient,
    createClient,
    createTokenClient,
    ApiException,
    AuthenticationError,
    AuthorizationError,
    ValidationError,
    NotFoundError,
    type ClientConfig,
} from './client';

// ============================================================================
// Type Exports
// ============================================================================

export type {
    // Common
    PaginationMeta,
    PaginatedResponse,
    ApiError,
    PaginationParams,

    // Auth
    User,
    LoginRequest,
    RegisterRequest,
    AuthResponse,
    LogoutResponse,

    // Agents
    Agent,
    CreateAgentRequest,
    UpdateAgentRequest,
    AttachToolsRequest,
    AttachToolsResponse,
    DetachToolResponse,
    AgentFilters,

    // Tools
    Tool,
    ToolType,
    ToolConfig,
    ApiToolConfig,
    FunctionToolConfig,
    CommandToolConfig,
    CreateToolRequest,
    UpdateToolRequest,

    // Files
    File,
    FileType,
    FileFilters,
    UploadFileRequest,

    // Executions
    Execution,
    ExecutionStatus,
    ExecutionResult,
    Task,
    ExecuteAgentRequest,
    ExecutionLogsResponse,
    ExecutionOutputsResponse,
    ExecutionFilters,

    // Streaming
    StreamEvent,
    StreamChunkEvent,
    StreamDoneEvent,
    StreamErrorEvent,
    StreamCallbacks,

    // AI Backends
    AIBackend,
    AIBackendCapabilities,
    AIBackendsResponse,
    AIModel,
    AIModelsResponse,
} from './types';

// ============================================================================
// Auth Module
// ============================================================================

export {
    AuthApi,
    register,
    login,
    logout,
    getCurrentUser,
    isAuthenticated,
    setToken,
    getToken,
    clearToken,
} from './auth';

// ============================================================================
// Agents Module
// ============================================================================

export {
    AgentsApi,
    listAgents,
    getAllAgents,
    createAgent,
    getAgent,
    updateAgent,
    deleteAgent,
    attachTools,
    detachTool,
    executeAgent,
    streamAgent,
} from './agents';

// ============================================================================
// Tools Module
// ============================================================================

export {
    ToolsApi,
    listTools,
    getAllTools,
    createTool,
    getTool,
    updateTool,
    deleteTool,
} from './tools';

// ============================================================================
// Files Module
// ============================================================================

export {
    FilesApi,
    listFiles,
    getAllFiles,
    uploadFile,
    getFile,
    downloadFile,
    downloadAndSaveFile,
    deleteFile,
} from './files';

// ============================================================================
// Executions Module
// ============================================================================

export {
    ExecutionsApi,
    listExecutions,
    getAllExecutions,
    getExecution,
    getExecutionLogs,
    getExecutionOutputs,
    waitForExecution,
} from './executions';

// ============================================================================
// AI Backends Module
// ============================================================================

export {
    AIBackendsApi,
    listAIBackends,
    getDefaultAIBackend,
    getAIBackend,
    listAIModels,
    isBackendAvailable,
    getStreamingBackends,
    getFunctionCallingBackends,
    getVisionBackends,
} from './ai-backends';

// ============================================================================
// API Client Instance (Convenience)
// ============================================================================

import { AuthApi } from './auth';
import { AgentsApi } from './agents';
import { ToolsApi } from './tools';
import { FilesApi } from './files';
import { ExecutionsApi } from './executions';
import { AIBackendsApi } from './ai-backends';

/**
 * Pre-configured API client with all modules
 */
export const api = {
    auth: new AuthApi(),
    agents: new AgentsApi(),
    tools: new ToolsApi(),
    files: new FilesApi(),
    executions: new ExecutionsApi(),
    aiBackends: new AIBackendsApi(),
};

export default api;
