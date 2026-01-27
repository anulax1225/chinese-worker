/**
 * AI Backends Module
 * Operations for viewing AI backends and their models
 */

import { getClient, type HttpClient } from './client';
import type {
    AIBackend,
    AIBackendsResponse,
    AIModel,
    AIModelsResponse,
} from './types';

/**
 * AI Backends API
 */
export class AIBackendsApi {
    constructor(private client: HttpClient = getClient()) {}

    /**
     * List all available AI backends
     */
    async list(): Promise<AIBackend[]> {
        const response = await this.client.get<AIBackendsResponse>('/ai-backends');
        return response.backends;
    }

    /**
     * Get the default AI backend
     */
    async getDefault(): Promise<AIBackend | undefined> {
        const backends = await this.list();
        return backends.find((b) => b.is_default);
    }

    /**
     * Get a specific AI backend by name
     */
    async get(name: string): Promise<AIBackend | undefined> {
        const backends = await this.list();
        return backends.find((b) => b.name === name);
    }

    /**
     * List models available for a specific backend
     */
    async listModels(backend: string): Promise<AIModel[]> {
        const response = await this.client.get<AIModelsResponse>(`/ai-backends/${backend}/models`);
        return response.models;
    }

    /**
     * Check if a backend is available (has streaming capability, etc.)
     */
    async isAvailable(backend: string): Promise<boolean> {
        try {
            const b = await this.get(backend);
            return b !== undefined;
        } catch {
            return false;
        }
    }

    /**
     * Get backends that support streaming
     */
    async getStreamingBackends(): Promise<AIBackend[]> {
        const backends = await this.list();
        return backends.filter((b) => b.capabilities.streaming);
    }

    /**
     * Get backends that support function calling
     */
    async getFunctionCallingBackends(): Promise<AIBackend[]> {
        const backends = await this.list();
        return backends.filter((b) => b.capabilities.function_calling);
    }

    /**
     * Get backends that support vision
     */
    async getVisionBackends(): Promise<AIBackend[]> {
        const backends = await this.list();
        return backends.filter((b) => b.capabilities.vision);
    }
}

// ============================================================================
// Standalone Functions
// ============================================================================

const defaultAIBackends = new AIBackendsApi();

/**
 * List all available AI backends
 */
export async function listAIBackends(): Promise<AIBackend[]> {
    return defaultAIBackends.list();
}

/**
 * Get the default AI backend
 */
export async function getDefaultAIBackend(): Promise<AIBackend | undefined> {
    return defaultAIBackends.getDefault();
}

/**
 * Get a specific AI backend by name
 */
export async function getAIBackend(name: string): Promise<AIBackend | undefined> {
    return defaultAIBackends.get(name);
}

/**
 * List models available for a specific backend
 */
export async function listAIModels(backend: string): Promise<AIModel[]> {
    return defaultAIBackends.listModels(backend);
}

/**
 * Check if a backend is available
 */
export async function isBackendAvailable(backend: string): Promise<boolean> {
    return defaultAIBackends.isAvailable(backend);
}

/**
 * Get backends that support streaming
 */
export async function getStreamingBackends(): Promise<AIBackend[]> {
    return defaultAIBackends.getStreamingBackends();
}

/**
 * Get backends that support function calling
 */
export async function getFunctionCallingBackends(): Promise<AIBackend[]> {
    return defaultAIBackends.getFunctionCallingBackends();
}

/**
 * Get backends that support vision
 */
export async function getVisionBackends(): Promise<AIBackend[]> {
    return defaultAIBackends.getVisionBackends();
}
