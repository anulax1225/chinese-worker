export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface Agent {
    id: number;
    user_id: number;
    name: string;
    description: string | null;
    code: string;
    config: Record<string, any>;
    status: 'active' | 'inactive' | 'error';
    ai_backend: 'ollama' | 'anthropic' | 'openai';
    created_at: string;
    updated_at: string;
    tools?: Tool[];
    executions?: Execution[];
}

export interface Tool {
    id: number;
    user_id: number;
    name: string;
    type: 'api' | 'function' | 'command';
    config: ToolConfig;
    created_at: string;
    updated_at: string;
    agents?: Agent[];
}

export type ToolConfig =
    | ApiToolConfig
    | FunctionToolConfig
    | CommandToolConfig;

export interface ApiToolConfig {
    url: string;
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH';
    headers: Record<string, string>;
}

export interface FunctionToolConfig {
    code: string;
}

export interface CommandToolConfig {
    command: string;
}

export interface File {
    id: number;
    user_id: number;
    path: string;
    type: 'input' | 'output' | 'temp';
    size: number;
    mime_type: string;
    created_at: string;
    updated_at: string;
}

export interface Task {
    id: number;
    agent_id: number;
    payload: Record<string, any>;
    priority: number;
    scheduled_at: string | null;
    created_at: string;
    updated_at: string;
    agent?: Agent;
    executions?: Execution[];
}

export interface Execution {
    id: number;
    task_id: number;
    status: 'pending' | 'running' | 'completed' | 'failed';
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

export interface ExecutionResult {
    content: string;
    model: string;
    tokens_used: number;
    finish_reason: string;
    metadata?: Record<string, any>;
}

export interface AIBackend {
    name: string;
    driver: string;
    is_default: boolean;
    capabilities: {
        streaming: boolean;
        function_calling: boolean;
        vision: boolean;
        max_context?: number;
    };
}

export interface AIModel {
    name: string;
    modified_at?: string;
    size?: number;
    digest?: string;
}

export interface PaginatedResponse<T> {
    data: T[];
    current_page: number;
    from: number | null;
    last_page: number;
    per_page: number;
    to: number | null;
    total: number;
}
