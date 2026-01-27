/**
 * API Client
 * Base HTTP client with authentication handling
 * Supports both Bearer token auth (for external clients) and
 * session-based auth with CSRF (for SPA usage)
 */

import type { ApiError } from './types';

// ============================================================================
// Configuration
// ============================================================================

export interface ClientConfig {
    baseUrl?: string;
    tokenKey?: string;
    /**
     * Use session-based authentication (cookies + CSRF)
     * Set to true when using from the SPA frontend
     */
    useSession?: boolean;
}

const DEFAULT_CONFIG: Required<ClientConfig> = {
    baseUrl: '/api/v1',
    tokenKey: 'api_token',
    useSession: true, // Default to session-based for SPA usage
};

// ============================================================================
// Error Classes
// ============================================================================

export class ApiException extends Error {
    constructor(
        message: string,
        public status: number,
        public errors?: Record<string, string[]>,
    ) {
        super(message);
        this.name = 'ApiException';
    }
}

export class AuthenticationError extends ApiException {
    constructor(message: string = 'Unauthenticated') {
        super(message, 401);
        this.name = 'AuthenticationError';
    }
}

export class AuthorizationError extends ApiException {
    constructor(message: string = 'Forbidden') {
        super(message, 403);
        this.name = 'AuthorizationError';
    }
}

export class ValidationError extends ApiException {
    constructor(
        message: string,
        errors: Record<string, string[]>,
    ) {
        super(message, 422, errors);
        this.name = 'ValidationError';
    }
}

export class NotFoundError extends ApiException {
    constructor(message: string = 'Not Found') {
        super(message, 404);
        this.name = 'NotFoundError';
    }
}

// ============================================================================
// CSRF Token Helper
// ============================================================================

/**
 * Get XSRF-TOKEN from cookie (Sanctum's default)
 */
function getXsrfTokenFromCookie(): string | null {
    const cookies = document.cookie.split(';');
    for (const cookie of cookies) {
        const [name, value] = cookie.trim().split('=');
        if (name === 'XSRF-TOKEN') {
            return decodeURIComponent(value);
        }
    }
    return null;
}

/**
 * Get CSRF token from meta tag
 */
function getCsrfTokenFromMeta(): string | null {
    const metaTag = document.querySelector('meta[name="csrf-token"]');
    if (metaTag) {
        return metaTag.getAttribute('content');
    }
    return null;
}

/**
 * Get CSRF token from meta tag or cookie
 */
function getCsrfToken(): string | null {
    // Try XSRF-TOKEN cookie first (Sanctum's SPA auth)
    const xsrfToken = getXsrfTokenFromCookie();
    if (xsrfToken) {
        return xsrfToken;
    }

    // Fall back to meta tag (Laravel's default)
    return getCsrfTokenFromMeta();
}

// ============================================================================
// HTTP Client
// ============================================================================

export class HttpClient {
    private config: Required<ClientConfig>;
    private token: string | null = null;

    constructor(config: ClientConfig = {}) {
        this.config = { ...DEFAULT_CONFIG, ...config };
        if (!this.config.useSession) {
            this.loadToken();
        }
    }

    /**
     * Get the current authentication token
     */
    getToken(): string | null {
        return this.token;
    }

    /**
     * Set the authentication token
     */
    setToken(token: string | null): void {
        this.token = token;
        if (token) {
            this.saveToken(token);
        } else {
            this.clearToken();
        }
    }

    /**
     * Check if user is authenticated (has token or using session)
     */
    isAuthenticated(): boolean {
        return this.config.useSession || this.token !== null;
    }

    /**
     * Ensure CSRF cookie is set (for Sanctum SPA auth)
     * Call this before making the first API request if using session-based auth
     */
    async ensureCsrf(): Promise<void> {
        if (!this.config.useSession) {
            return;
        }

        // Check if we already have a CSRF token
        if (getCsrfToken()) {
            return;
        }

        // Fetch the CSRF cookie from Sanctum
        await fetch('/sanctum/csrf-cookie', {
            method: 'GET',
            credentials: 'include',
        });
    }

    /**
     * Clear the authentication token
     */
    clearToken(): void {
        this.token = null;
        if (typeof localStorage !== 'undefined') {
            localStorage.removeItem(this.config.tokenKey);
        }
    }

    /**
     * Build URL with query parameters
     */
    private buildUrl(path: string, params?: Record<string, unknown>): string {
        const url = new URL(this.config.baseUrl + path, window.location.origin);

        if (params) {
            Object.entries(params).forEach(([key, value]) => {
                if (value !== undefined && value !== null) {
                    url.searchParams.append(key, String(value));
                }
            });
        }

        return url.toString();
    }

    /**
     * Build request headers
     */
    private buildHeaders(customHeaders?: Record<string, string>, includeContentType: boolean = true): Headers {
        const headers = new Headers({
            'Accept': 'application/json',
            ...customHeaders,
        });

        if (includeContentType) {
            headers.set('Content-Type', 'application/json');
        }

        // Add CSRF token for session-based auth
        if (this.config.useSession) {
            const csrfToken = getCsrfToken();
            if (csrfToken) {
                headers.set('X-XSRF-TOKEN', csrfToken);
            }
            headers.set('X-Requested-With', 'XMLHttpRequest');
        }

        // Add Bearer token for token-based auth
        if (!this.config.useSession && this.token) {
            headers.set('Authorization', `Bearer ${this.token}`);
        }

        return headers;
    }

    /**
     * Get fetch options with credentials
     */
    private getFetchOptions(): RequestInit {
        return this.config.useSession
            ? { credentials: 'include' }
            : {};
    }

    /**
     * Handle response errors
     */
    private async handleError(response: Response): Promise<never> {
        let errorData: ApiError;

        try {
            errorData = await response.json();
        } catch {
            errorData = { message: response.statusText || 'An error occurred' };
        }

        switch (response.status) {
            case 401:
                if (!this.config.useSession) {
                    this.clearToken();
                }
                throw new AuthenticationError(errorData.message);
            case 403:
                throw new AuthorizationError(errorData.message);
            case 404:
                throw new NotFoundError(errorData.message);
            case 422:
                throw new ValidationError(errorData.message, errorData.errors || {});
            default:
                throw new ApiException(errorData.message, response.status, errorData.errors);
        }
    }

    /**
     * Make a GET request
     */
    async get<T>(path: string, params?: Record<string, unknown>): Promise<T> {
        const response = await fetch(this.buildUrl(path, params), {
            method: 'GET',
            headers: this.buildHeaders(),
            ...this.getFetchOptions(),
        });

        if (!response.ok) {
            await this.handleError(response);
        }

        return response.json();
    }

    /**
     * Make a POST request
     */
    async post<T>(path: string, data?: unknown): Promise<T> {
        const response = await fetch(this.buildUrl(path), {
            method: 'POST',
            headers: this.buildHeaders(),
            body: data ? JSON.stringify(data) : undefined,
            ...this.getFetchOptions(),
        });

        if (!response.ok) {
            await this.handleError(response);
        }

        return response.json();
    }

    /**
     * Make a PUT request
     */
    async put<T>(path: string, data?: unknown): Promise<T> {
        const response = await fetch(this.buildUrl(path), {
            method: 'PUT',
            headers: this.buildHeaders(),
            body: data ? JSON.stringify(data) : undefined,
            ...this.getFetchOptions(),
        });

        if (!response.ok) {
            await this.handleError(response);
        }

        return response.json();
    }

    /**
     * Make a DELETE request
     */
    async delete<T = void>(path: string): Promise<T> {
        const response = await fetch(this.buildUrl(path), {
            method: 'DELETE',
            headers: this.buildHeaders(),
            ...this.getFetchOptions(),
        });

        if (!response.ok) {
            await this.handleError(response);
        }

        // Handle 204 No Content responses
        if (response.status === 204) {
            return undefined as T;
        }

        return response.json();
    }

    /**
     * Upload a file using multipart/form-data
     */
    async upload<T>(path: string, data: FormData): Promise<T> {
        const response = await fetch(this.buildUrl(path), {
            method: 'POST',
            headers: this.buildHeaders(undefined, false), // Don't set Content-Type for FormData
            body: data,
            ...this.getFetchOptions(),
        });

        if (!response.ok) {
            await this.handleError(response);
        }

        return response.json();
    }

    /**
     * Download a file as a Blob
     */
    async download(path: string): Promise<Blob> {
        const headers = new Headers();
        headers.set('Accept', '*/*');

        if (this.config.useSession) {
            const csrfToken = getCsrfToken();
            if (csrfToken) {
                headers.set('X-XSRF-TOKEN', csrfToken);
            }
            headers.set('X-Requested-With', 'XMLHttpRequest');
        } else if (this.token) {
            headers.set('Authorization', `Bearer ${this.token}`);
        }

        const response = await fetch(this.buildUrl(path), {
            method: 'GET',
            headers,
            ...this.getFetchOptions(),
        });

        if (!response.ok) {
            await this.handleError(response);
        }

        return response.blob();
    }

    /**
     * Create a Server-Sent Events connection
     */
    stream(path: string, data: unknown, callbacks: {
        onMessage: (event: MessageEvent) => void;
        onError?: (error: Event) => void;
        onOpen?: () => void;
    }): { close: () => void } {
        const controller = new AbortController();

        const startStream = async () => {
            try {
                const response = await fetch(this.buildUrl(path), {
                    method: 'POST',
                    headers: this.buildHeaders(),
                    body: JSON.stringify(data),
                    signal: controller.signal,
                    ...this.getFetchOptions(),
                });

                if (!response.ok) {
                    await this.handleError(response);
                }

                if (!response.body) {
                    throw new Error('Response body is null');
                }

                callbacks.onOpen?.();

                const reader = response.body.getReader();
                const decoder = new TextDecoder();
                let buffer = '';

                while (true) {
                    const { done, value } = await reader.read();

                    if (done) {
                        break;
                    }

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        if (line.startsWith('data: ')) {
                            const eventData = line.slice(6);
                            callbacks.onMessage(new MessageEvent('message', { data: eventData }));
                        }
                    }
                }

                // Process remaining buffer
                if (buffer.startsWith('data: ')) {
                    const eventData = buffer.slice(6);
                    callbacks.onMessage(new MessageEvent('message', { data: eventData }));
                }
            } catch (error) {
                if (error instanceof Error && error.name !== 'AbortError') {
                    callbacks.onError?.(new ErrorEvent('error', { error }));
                }
            }
        };

        startStream();

        return {
            close: () => controller.abort(),
        };
    }

    /**
     * Load token from storage
     */
    private loadToken(): void {
        if (typeof localStorage !== 'undefined') {
            this.token = localStorage.getItem(this.config.tokenKey);
        }
    }

    /**
     * Save token to storage
     */
    private saveToken(token: string): void {
        if (typeof localStorage !== 'undefined') {
            localStorage.setItem(this.config.tokenKey, token);
        }
    }
}

// ============================================================================
// Default Client Instance
// ============================================================================

let defaultClient: HttpClient | null = null;

/**
 * Get the default HTTP client instance
 */
export function getClient(): HttpClient {
    if (!defaultClient) {
        defaultClient = new HttpClient();
    }
    return defaultClient;
}

/**
 * Configure the default HTTP client
 */
export function configureClient(config: ClientConfig): HttpClient {
    defaultClient = new HttpClient(config);
    return defaultClient;
}

/**
 * Create a new HTTP client instance
 */
export function createClient(config: ClientConfig = {}): HttpClient {
    return new HttpClient(config);
}

/**
 * Create a client for external API usage (with Bearer token)
 */
export function createTokenClient(config: Omit<ClientConfig, 'useSession'> = {}): HttpClient {
    return new HttpClient({ ...config, useSession: false });
}
