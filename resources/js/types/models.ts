export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ModelConfig {
    model?: string;
    temperature?: number;
    max_tokens?: number;
    top_p?: number;
    top_k?: number;
    context_length?: number;
    frequency_penalty?: number;
    presence_penalty?: number;
    timeout?: number;
    stop_sequences?: string[];
}

export interface Agent {
    id: number;
    user?: User; // Optional relation loaded via AgentResource
    name: string;
    description: string | null;
    code: string;
    config: Record<string, any>;
    model_config: ModelConfig | null;
    status: 'active' | 'inactive' | 'error';
    ai_backend: 'ollama' | 'anthropic' | 'openai';
    context_variables: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
    tools?: Tool[];
    system_prompts?: SystemPrompt[];
}

export interface SystemPrompt {
    id: number;
    name: string;
    slug: string;
    template: string;
    required_variables: string[] | null;
    default_values: Record<string, unknown> | null;
    is_active: boolean;
    created_at: string;
    updated_at: string;
    pivot?: {
        order: number;
        variable_overrides: Record<string, unknown> | null;
    };
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

export type DocumentStatus = 'pending' | 'extracting' | 'cleaning' | 'normalizing' | 'chunking' | 'ready' | 'failed';
export type DocumentSourceType = 'upload' | 'url' | 'paste';

export interface DocumentStage {
    id: number;
    document_id: number;
    stage: 'extracted' | 'cleaned' | 'normalized' | 'chunked';
    content: string;
    metadata: Record<string, unknown>;
    created_at: string;
}

export interface Document {
    id: number;
    user_id: number;
    file_id: number | null;
    title: string | null;
    source_type: DocumentSourceType;
    source_path: string;
    mime_type: string;
    file_size: number;
    status: DocumentStatus;
    error_message: string | null;
    metadata: Record<string, unknown> | null;
    processing_started_at: string | null;
    processing_completed_at: string | null;
    created_at: string;
    updated_at: string;
    stages?: DocumentStage[];
    file?: File;
}

export interface AIBackend {
    name: string;
    driver: string;
    is_default: boolean;
    model?: string;
    status: 'connected' | 'error' | 'unknown';
    capabilities: {
        streaming: boolean;
        function_calling: boolean;
        vision: boolean;
        embeddings?: boolean;
        max_context?: number;
    };
    models: AIModel[];
    error?: string;
}

export interface AIModel {
    name: string;
    modified_at?: string;
    size?: number;
    size_human?: string;
    digest?: string;
    family?: string;
    parameter_size?: string;
    quantization_level?: string;
    details?: Record<string, unknown>;
}

export interface ModelPullProgress {
    status: string;
    digest?: string;
    total?: number;
    completed?: number;
    percentage?: number;
    error?: string;
}

export interface ModelPullResponse {
    pull_id: string;
    model: string;
    backend: string;
    status: 'queued';
    stream_url: string;
}

export interface TokenUsage {
    prompt_tokens: number;
    completion_tokens: number;
    total_tokens: number;
    context_limit: number | null;
    estimated_context_usage: number;
    usage_percentage: number | null;
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
    token_usage: TokenUsage;
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

export interface MessageAttachment {
    id: string;
    type: 'image' | 'document';
    document_id: number | null;
    filename: string;
    mime_type: string;
    storage_path: string | null;
    metadata: Record<string, unknown> | null;
    created_at: string;
}

export interface ChatMessage {
    id?: string;
    position?: number;
    role: 'user' | 'assistant' | 'system' | 'tool';
    content: string;
    thinking?: string;
    tool_call_id?: string;
    tool_calls?: ToolCall[];
    attachments?: MessageAttachment[];
    name?: string;
    token_count?: number;
    counted_at?: string;
    created_at?: string;
}

export interface ToolRequest {
    call_id: string;
    name: string;
    arguments: Record<string, unknown>;
}

export interface ToolCall {
    id: string;
    call_id: string;
    function_name: string;
    name: string;
    arguments: Record<string, unknown>;
    position: number;
    created_at: string;
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
