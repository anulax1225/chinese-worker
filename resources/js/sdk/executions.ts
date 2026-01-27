/**
 * Executions Module
 * Operations for viewing and managing executions
 */

import { getClient, type HttpClient } from './client';
import type {
    Execution,
    ExecutionFilters,
    ExecutionLogsResponse,
    ExecutionOutputsResponse,
    PaginatedResponse,
    File,
} from './types';

/**
 * Executions API
 */
export class ExecutionsApi {
    constructor(private client: HttpClient = getClient()) {}

    /**
     * List all executions with optional filtering and pagination
     */
    async list(filters?: ExecutionFilters): Promise<PaginatedResponse<Execution>> {
        return this.client.get<PaginatedResponse<Execution>>('/executions', filters as Record<string, unknown>);
    }

    /**
     * Get all executions without pagination
     */
    async all(filters?: Omit<ExecutionFilters, 'page' | 'per_page'>): Promise<Execution[]> {
        const executions: Execution[] = [];
        let page = 1;
        let hasMore = true;

        while (hasMore) {
            const response = await this.list({ ...filters, page, per_page: 100 });
            executions.push(...response.data);
            hasMore = response.meta.current_page < response.meta.last_page;
            page++;
        }

        return executions;
    }

    /**
     * Get a single execution by ID
     */
    async get(id: number): Promise<Execution> {
        return this.client.get<Execution>(`/executions/${id}`);
    }

    /**
     * Get execution logs
     */
    async getLogs(id: number): Promise<string | null> {
        const response = await this.client.get<ExecutionLogsResponse>(`/executions/${id}/logs`);
        return response.logs;
    }

    /**
     * Get execution output files
     */
    async getOutputs(id: number): Promise<File[]> {
        const response = await this.client.get<ExecutionOutputsResponse>(`/executions/${id}/outputs`);
        return response.outputs;
    }

    /**
     * Poll execution status until completion or timeout
     */
    async waitForCompletion(
        id: number,
        options: {
            pollInterval?: number;
            timeout?: number;
            onUpdate?: (execution: Execution) => void;
        } = {},
    ): Promise<Execution> {
        const { pollInterval = 1000, timeout = 300000, onUpdate } = options;
        const startTime = Date.now();

        while (true) {
            const execution = await this.get(id);
            onUpdate?.(execution);

            if (execution.status === 'completed' || execution.status === 'failed' || execution.status === 'cancelled') {
                return execution;
            }

            if (Date.now() - startTime > timeout) {
                throw new Error(`Execution ${id} timed out after ${timeout}ms`);
            }

            await new Promise((resolve) => setTimeout(resolve, pollInterval));
        }
    }

    /**
     * Cancel a running or pending execution
     */
    async cancel(id: number): Promise<{ message: string; execution: Execution }> {
        return this.client.post<{ message: string; execution: Execution }>(`/executions/${id}/cancel`);
    }
}

// ============================================================================
// Standalone Functions
// ============================================================================

const defaultExecutions = new ExecutionsApi();

/**
 * List all executions with optional filtering and pagination
 */
export async function listExecutions(filters?: ExecutionFilters): Promise<PaginatedResponse<Execution>> {
    return defaultExecutions.list(filters);
}

/**
 * Get all executions without pagination
 */
export async function getAllExecutions(filters?: Omit<ExecutionFilters, 'page' | 'per_page'>): Promise<Execution[]> {
    return defaultExecutions.all(filters);
}

/**
 * Get a single execution by ID
 */
export async function getExecution(id: number): Promise<Execution> {
    return defaultExecutions.get(id);
}

/**
 * Get execution logs
 */
export async function getExecutionLogs(id: number): Promise<string | null> {
    return defaultExecutions.getLogs(id);
}

/**
 * Get execution output files
 */
export async function getExecutionOutputs(id: number): Promise<File[]> {
    return defaultExecutions.getOutputs(id);
}

/**
 * Poll execution status until completion or timeout
 */
export async function waitForExecution(
    id: number,
    options?: {
        pollInterval?: number;
        timeout?: number;
        onUpdate?: (execution: Execution) => void;
    },
): Promise<Execution> {
    return defaultExecutions.waitForCompletion(id, options);
}

/**
 * Cancel a running or pending execution
 */
export async function cancelExecution(id: number): Promise<{ message: string; execution: Execution }> {
    return defaultExecutions.cancel(id);
}
