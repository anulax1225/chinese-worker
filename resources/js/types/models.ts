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
}

export interface Tool {
    id: number | string; // string for builtin tools like 'builtin_read'
    user_id: number | null; // null for builtin tools
    name: string;
    type: 'api' | 'function' | 'command' | 'builtin';
    config?: ToolConfig;
    description?: string; // for builtin tools
    parameters?: Record<string, unknown>; // schema for builtin tools
    created_at: string | null;
    updated_at: string | null;
    agents?: Agent[];
    agents_count?: number;
}

export type ToolConfig =
    | ApiToolConfig
    | FunctionToolConfig
    | CommandToolConfig;

export interface ApiToolConfig {
    url: string;
    method: 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH';
    headers?: Record<string, string>;
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

export interface Conversation {
    id: number;
    agent_id: number;
    user_id: number;
    status: 'active' | 'completed' | 'failed' | 'cancelled' | 'waiting_tool';
    messages: ChatMessage[];
    metadata: Record<string, unknown>;
    turn_count: number;
    total_tokens: number;
    started_at: string | null;
    last_activity_at: string | null;
    completed_at: string | null;
    cli_session_id: string | null;
    waiting_for: string | null;
    pending_tool_request: ToolRequest | null;
    client_type: 'cli' | 'cli_web' | 'api';
    client_tool_schemas: Record<string, unknown>[] | null;
    created_at: string;
    updated_at: string;
    agent?: Agent;
}

export interface ChatMessage {
    role: 'user' | 'assistant' | 'system' | 'tool';
    content: string;
    thinking?: string;
    tool_call_id?: string;
    tool_calls?: ToolCall[];
    name?: string;
}

export interface ToolRequest {
    call_id: string;
    name: string;
    arguments: Record<string, unknown>;
}

export interface ToolCall {
    id: string;
    type: 'function';
    function: {
        name: string;
        arguments: string;
    };
}

export interface PersonalAccessToken {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string;
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
