import type { Agent, Tool, File, Execution, AIBackend, AIModel, PaginatedResponse } from './models';

// Authentication
export interface LoginRequest {
    email: string;
    password: string;
}

export interface RegisterRequest {
    name: string;
    email: string;
    password: string;
}

export interface AuthResponse {
    user: {
        id: number;
        name: string;
        email: string;
    };
    token: string;
}

// Agents
export interface CreateAgentRequest {
    name: string;
    description?: string;
    code: string;
    config?: Record<string, any>;
    status?: 'active' | 'inactive' | 'error';
    ai_backend?: 'ollama' | 'anthropic' | 'openai';
    tool_ids?: number[];
}

export interface UpdateAgentRequest {
    name?: string;
    description?: string;
    code?: string;
    config?: Record<string, any>;
    status?: 'active' | 'inactive' | 'error';
    ai_backend?: 'ollama' | 'anthropic' | 'openai';
    tool_ids?: number[];
}

export interface AttachToolsRequest {
    tool_ids: number[];
}

// Tools
export interface CreateToolRequest {
    name: string;
    type: 'api' | 'function' | 'command';
    config: Record<string, any>;
}

export interface UpdateToolRequest {
    name?: string;
    type?: 'api' | 'function' | 'command';
    config?: Record<string, any>;
}

// Files
export interface UploadFileRequest {
    file: File;
    type: 'input' | 'output' | 'temp';
}

// Executions
export interface ExecuteAgentRequest {
    payload: {
        input?: string;
        parameters?: Record<string, any>;
    };
    file_ids?: number[];
    priority?: number;
    scheduled_at?: string;
}

// API Response Types
export interface AgentsListResponse extends PaginatedResponse<Agent> {}
export interface ToolsListResponse extends PaginatedResponse<Tool> {}
export interface FilesListResponse extends PaginatedResponse<File> {}
export interface ExecutionsListResponse extends PaginatedResponse<Execution> {}

export interface AIBackendsResponse {
    backends: AIBackend[];
}

export interface AIBackendModelsResponse {
    models: AIModel[];
}

export interface ExecutionLogsResponse {
    logs: string;
}

export interface ExecutionOutputsResponse {
    outputs: File[];
}

// Streaming Events
export interface StreamChunkEvent {
    type: 'chunk';
    content: string;
}

export interface StreamDoneEvent {
    type: 'done';
    execution_id: number;
    status: 'completed' | 'failed';
}

export type StreamEvent = StreamChunkEvent | StreamDoneEvent;

// WebSocket Events
export interface ExecutionUpdatedEvent {
    id: number;
    task_id: number;
    status: 'pending' | 'running' | 'completed' | 'failed';
    started_at: string | null;
    completed_at: string | null;
    result: Record<string, any> | null;
    error: string | null;
    updated_at: string;
}
