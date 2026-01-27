/**
 * Tools Module
 * CRUD operations for tools
 */

import { getClient, type HttpClient } from './client';
import type {
    Tool,
    CreateToolRequest,
    UpdateToolRequest,
    ToolFilters,
} from './types';

/**
 * API response for paginated tools
 * The backend returns a flat structure, not nested under 'meta'
 */
export interface ToolsListResponse {
    data: Tool[];
    meta: {
        current_page: number;
        per_page: number;
        total: number;
        last_page: number;
    };
}

/**
 * Tools API
 */
export class ToolsApi {
    constructor(private client: HttpClient = getClient()) {}

    /**
     * List all tools with optional filters and pagination
     * Includes builtin tools by default
     */
    async list(params?: ToolFilters): Promise<ToolsListResponse> {
        return this.client.get<ToolsListResponse>('/tools', params as Record<string, unknown>);
    }

    /**
     * Get all tools without pagination
     */
    async all(includeBuiltin = true): Promise<Tool[]> {
        const tools: Tool[] = [];
        let page = 1;
        let hasMore = true;

        while (hasMore) {
            const response = await this.list({ page, per_page: 100, include_builtin: includeBuiltin });
            tools.push(...response.data);
            hasMore = response.meta.current_page < response.meta.last_page;
            page++;
        }

        return tools;
    }

    /**
     * Create a new tool
     */
    async create(data: CreateToolRequest): Promise<Tool> {
        return this.client.post<Tool>('/tools', data);
    }

    /**
     * Get a single tool by ID
     */
    async get(id: number | string): Promise<Tool> {
        return this.client.get<Tool>(`/tools/${id}`);
    }

    /**
     * Update an existing tool
     */
    async update(id: number, data: UpdateToolRequest): Promise<Tool> {
        return this.client.put<Tool>(`/tools/${id}`, data);
    }

    /**
     * Delete a tool
     */
    async delete(id: number): Promise<void> {
        return this.client.delete(`/tools/${id}`);
    }
}

// ============================================================================
// Standalone Functions
// ============================================================================

const defaultTools = new ToolsApi();

/**
 * List all tools with optional filters and pagination
 */
export async function listTools(params?: ToolFilters): Promise<ToolsListResponse> {
    return defaultTools.list(params);
}

/**
 * Get all tools without pagination
 */
export async function getAllTools(includeBuiltin = true): Promise<Tool[]> {
    return defaultTools.all(includeBuiltin);
}

/**
 * Create a new tool
 */
export async function createTool(data: CreateToolRequest): Promise<Tool> {
    return defaultTools.create(data);
}

/**
 * Get a single tool by ID
 */
export async function getTool(id: number | string): Promise<Tool> {
    return defaultTools.get(id);
}

/**
 * Update an existing tool
 */
export async function updateTool(id: number, data: UpdateToolRequest): Promise<Tool> {
    return defaultTools.update(id, data);
}

/**
 * Delete a tool
 */
export async function deleteTool(id: number): Promise<void> {
    return defaultTools.delete(id);
}
