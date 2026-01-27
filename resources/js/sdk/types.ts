/**
 * SDK Types
 * Type definitions for API requests and responses
 */

// ============================================================================
// Common Types
// ============================================================================

export interface PaginationMeta {
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}

export interface PaginatedResponse<T> {
    data: T[];
    meta: PaginationMeta;
    links: {
        first: string | null;
        last: string | null;
        prev: string | null;
        next: string | null;
    };
}

export interface ApiError {
    message: string;
    errors?: Record<string, string[]>;
}

// ============================================================================
// Auth Types
// ============================================================================

export interface User {
    id: number;
    name: string;
    email: string;
    created_at: string;
    updated_at: string;
}

export interface LoginRequest {
    email: string;
    password: string;
}

export interface RegisterRequest {
    name: string;
    email: string;
    password: string;
    password_confirmation?: string;
}

export interface AuthResponse {
    user: User;
    token: string;
}

export interface LogoutResponse {
    message: string;
}

// ============================================================================
// Agent Types
// ============================================================================

export interface Agent {
    id: number;
    user_id: number;
    name: string;
    description: string | null;
    code: string;
    config: Record<string, unknown>;
    status: 'active' | 'inactive' | 'error';
    ai_backend: string;
    created_at: string;
    updated_at: string;
    tools?: Tool[];
    tools_count?: number;
    executions_count?: number;
}

export interface CreateAgentRequest {
    name: string;
    description?: string;
    code: string;
    config?: Record<string, unknown>;
    status?: 'active' | 'inactive' | 'error';
    ai_backend?: string;
    tool_ids?: number[];
}

export interface UpdateAgentRequest {
    name?: string;
    description?: string;
    code?: string;
    config?: Record<string, unknown>;
    status?: 'active' | 'inactive' | 'error';
    ai_backend?: string;
    tool_ids?: number[];
}

export interface AttachToolsRequest {
    tool_ids: number[];
}

export interface AttachToolsResponse {
    message: string;
    agent: Agent;
}

export interface DetachToolResponse {
    message: string;
}

// ============================================================================
// Tool Types
// ============================================================================

export type ToolType = 'api' | 'function' | 'command' | 'builtin';

export interface ApiToolConfig {
    url: string;
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    headers?: Record<string, string>;
}

export interface FunctionToolConfig {
    code: string;
}

export interface CommandToolConfig {
    command: string;
}

export interface BuiltinToolParameters {
    type: 'object';
    properties: Record<string, unknown>;
    required?: string[];
}

export type ToolConfig = ApiToolConfig | FunctionToolConfig | CommandToolConfig;

export interface Tool {
    id: number | string; // string for builtin tools like 'builtin_read'
    user_id: number | null; // null for builtin tools
    name: string;
    type: ToolType;
    config?: ToolConfig;
    description?: string; // for builtin tools
    parameters?: BuiltinToolParameters; // schema for builtin tools
    created_at: string | null;
    updated_at: string | null;
    agents_count?: number;
}

export interface CreateToolRequest {
    name: string;
    type: ToolType;
    config: ToolConfig;
}

export interface UpdateToolRequest {
    name?: string;
    type?: ToolType;
    config?: ToolConfig;
}

// ============================================================================
// File Types
// ============================================================================

export type FileType = 'input' | 'output' | 'temp';

export interface File {
    id: number;
    user_id: number;
    path: string;
    type: FileType;
    size: number;
    mime_type: string;
    created_at: string;
    updated_at: string;
}

export interface UploadFileRequest {
    file: Blob | globalThis.File;
    type: FileType;
}

// ============================================================================
// Execution Types
// ============================================================================

export type ExecutionStatus = 'pending' | 'running' | 'completed' | 'failed' | 'cancelled';

export interface ExecutionResult {
    content?: string;
    model?: string;
    tokens_used?: number;
    finish_reason?: string;
}

export interface Task {
    id: number;
    agent_id: number;
    payload: Record<string, unknown>;
    priority: number;
    scheduled_at: string | null;
    created_at: string;
    updated_at: string;
    agent?: Agent;
}

export interface Execution {
    id: number;
    task_id: number;
    status: ExecutionStatus;
    started_at: string | null;
    completed_at: string | null;
    result: ExecutionResult | null;
    logs: string | null;
    error: string | null;
    created_at: string;
    updated_at: string;
    task?: Task;
    files?: File[];
}

export interface ExecuteAgentRequest {
    payload: Record<string, unknown>;
    file_ids?: number[];
    priority?: number;
    scheduled_at?: string;
}

export interface ExecutionLogsResponse {
    logs: string | null;
}

export interface ExecutionOutputsResponse {
    outputs: File[];
}

// ============================================================================
// Streaming Types
// ============================================================================

export interface StreamChunkEvent {
    type: 'chunk';
    content: string;
}

export interface StreamDoneEvent {
    type: 'done';
    execution_id: number;
    status: ExecutionStatus;
}

export interface StreamErrorEvent {
    type: 'error';
    message: string;
}

export type StreamEvent = StreamChunkEvent | StreamDoneEvent | StreamErrorEvent;

export interface StreamCallbacks {
    onChunk?: (content: string) => void;
    onDone?: (executionId: number, status: ExecutionStatus) => void;
    onError?: (message: string) => void;
}

// ============================================================================
// AI Backend Types
// ============================================================================

export interface AIBackendCapabilities {
    streaming: boolean;
    function_calling: boolean;
    vision: boolean;
    embeddings?: boolean;
    max_context?: number;
}

export interface AIBackend {
    name: string;
    driver: string;
    is_default: boolean;
    model?: string | null;
    capabilities: AIBackendCapabilities;
    models: AIModel[];
    status: 'connected' | 'error' | 'unknown';
    error?: string;
}

export interface AIBackendsResponse {
    backends: AIBackend[];
    default_backend: string;
}

export interface AIModel {
    name: string;
    size?: number;
    modified_at?: string;
}

export interface AIModelsResponse {
    models: AIModel[];
}

// ============================================================================
// Query Parameters
// ============================================================================

export interface PaginationParams {
    page?: number;
    per_page?: number;
}

export interface AgentFilters extends PaginationParams {
    status?: 'active' | 'inactive' | 'error';
    search?: string;
}

export interface ExecutionFilters extends PaginationParams {
    status?: ExecutionStatus;
    agent_id?: number;
}

export interface FileFilters extends PaginationParams {
    type?: FileType;
}

export interface ToolFilters extends PaginationParams {
    type?: ToolType;
    search?: string;
    include_builtin?: boolean;
}
