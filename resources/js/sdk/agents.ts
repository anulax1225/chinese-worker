/**
 * Agents Module
 * CRUD operations for agents + tool management + execution
 */

import { getClient, type HttpClient } from './client';
import type {
    Agent,
    CreateAgentRequest,
    UpdateAgentRequest,
    AttachToolsRequest,
    AttachToolsResponse,
    DetachToolResponse,
    ExecuteAgentRequest,
    Execution,
    AgentFilters,
    PaginatedResponse,
    StreamCallbacks,
    StreamEvent,
} from './types';

/**
 * Agents API
 */
export class AgentsApi {
    constructor(private client: HttpClient = getClient()) {}

    /**
     * List all agents with optional filtering and pagination
     */
    async list(filters?: AgentFilters): Promise<PaginatedResponse<Agent>> {
        return this.client.get<PaginatedResponse<Agent>>('/agents', filters as Record<string, unknown>);
    }

    /**
     * Get all agents without pagination
     */
    async all(): Promise<Agent[]> {
        const agents: Agent[] = [];
        let page = 1;
        let hasMore = true;

        while (hasMore) {
            const response = await this.list({ page, per_page: 100 });
            agents.push(...response.data);
            hasMore = response.meta.current_page < response.meta.last_page;
            page++;
        }

        return agents;
    }

    /**
     * Create a new agent
     */
    async create(data: CreateAgentRequest): Promise<Agent> {
        return this.client.post<Agent>('/agents', data);
    }

    /**
     * Get a single agent by ID
     */
    async get(id: number): Promise<Agent> {
        return this.client.get<Agent>(`/agents/${id}`);
    }

    /**
     * Update an existing agent
     */
    async update(id: number, data: UpdateAgentRequest): Promise<Agent> {
        return this.client.put<Agent>(`/agents/${id}`, data);
    }

    /**
     * Delete an agent
     */
    async delete(id: number): Promise<void> {
        return this.client.delete(`/agents/${id}`);
    }

    /**
     * Attach tools to an agent
     */
    async attachTools(agentId: number, data: AttachToolsRequest): Promise<AttachToolsResponse> {
        return this.client.post<AttachToolsResponse>(`/agents/${agentId}/tools`, data);
    }

    /**
     * Detach a tool from an agent
     */
    async detachTool(agentId: number, toolId: number): Promise<DetachToolResponse> {
        return this.client.delete<DetachToolResponse>(`/agents/${agentId}/tools/${toolId}`);
    }

    /**
     * Execute an agent (non-streaming)
     */
    async execute(agentId: number, data: ExecuteAgentRequest): Promise<Execution> {
        return this.client.post<Execution>(`/agents/${agentId}/execute`, data);
    }

    /**
     * Execute an agent with streaming response
     */
    stream(agentId: number, data: ExecuteAgentRequest, callbacks: StreamCallbacks): { close: () => void } {
        return this.client.stream(`/agents/${agentId}/stream`, data, {
            onMessage: (event) => {
                try {
                    const parsed = JSON.parse(event.data) as StreamEvent;

                    switch (parsed.type) {
                        case 'chunk':
                            callbacks.onChunk?.(parsed.content);
                            break;
                        case 'done':
                            callbacks.onDone?.(parsed.execution_id, parsed.status);
                            break;
                        case 'error':
                            callbacks.onError?.(parsed.message);
                            break;
                    }
                } catch (error) {
                    callbacks.onError?.(`Failed to parse stream event: ${event.data}`);
                }
            },
            onError: (error) => {
                callbacks.onError?.(error instanceof ErrorEvent ? error.message : 'Stream error');
            },
        });
    }
}

// ============================================================================
// Standalone Functions
// ============================================================================

const defaultAgents = new AgentsApi();

/**
 * List all agents with optional filtering and pagination
 */
export async function listAgents(filters?: AgentFilters): Promise<PaginatedResponse<Agent>> {
    return defaultAgents.list(filters);
}

/**
 * Get all agents without pagination
 */
export async function getAllAgents(): Promise<Agent[]> {
    return defaultAgents.all();
}

/**
 * Create a new agent
 */
export async function createAgent(data: CreateAgentRequest): Promise<Agent> {
    return defaultAgents.create(data);
}

/**
 * Get a single agent by ID
 */
export async function getAgent(id: number): Promise<Agent> {
    return defaultAgents.get(id);
}

/**
 * Update an existing agent
 */
export async function updateAgent(id: number, data: UpdateAgentRequest): Promise<Agent> {
    return defaultAgents.update(id, data);
}

/**
 * Delete an agent
 */
export async function deleteAgent(id: number): Promise<void> {
    return defaultAgents.delete(id);
}

/**
 * Attach tools to an agent
 */
export async function attachTools(agentId: number, toolIds: number[]): Promise<AttachToolsResponse> {
    return defaultAgents.attachTools(agentId, { tool_ids: toolIds });
}

/**
 * Detach a tool from an agent
 */
export async function detachTool(agentId: number, toolId: number): Promise<DetachToolResponse> {
    return defaultAgents.detachTool(agentId, toolId);
}

/**
 * Execute an agent (non-streaming)
 */
export async function executeAgent(agentId: number, data: ExecuteAgentRequest): Promise<Execution> {
    return defaultAgents.execute(agentId, data);
}

/**
 * Execute an agent with streaming response
 */
export function streamAgent(agentId: number, data: ExecuteAgentRequest, callbacks: StreamCallbacks): { close: () => void } {
    return defaultAgents.stream(agentId, data, callbacks);
}
