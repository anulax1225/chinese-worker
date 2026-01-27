/**
 * Authentication Module
 * Handles user registration, login, and logout
 */

import { getClient, type HttpClient } from './client';
import type {
    LoginRequest,
    RegisterRequest,
    AuthResponse,
    LogoutResponse,
    User,
} from './types';

/**
 * Auth API
 */
export class AuthApi {
    constructor(private client: HttpClient = getClient()) {}

    /**
     * Register a new user
     */
    async register(data: RegisterRequest): Promise<AuthResponse> {
        const response = await this.client.post<AuthResponse>('/auth/register', data);
        this.client.setToken(response.token);
        return response;
    }

    /**
     * Login with email and password
     */
    async login(data: LoginRequest): Promise<AuthResponse> {
        const response = await this.client.post<AuthResponse>('/auth/login', data);
        this.client.setToken(response.token);
        return response;
    }

    /**
     * Logout the current user
     */
    async logout(): Promise<LogoutResponse> {
        const response = await this.client.post<LogoutResponse>('/auth/logout');
        this.client.clearToken();
        return response;
    }

    /**
     * Get the current authenticated user
     */
    async getCurrentUser(): Promise<User> {
        return this.client.get<User>('/auth/user');
    }

    /**
     * Check if the user is authenticated (has a valid token)
     */
    isAuthenticated(): boolean {
        return this.client.isAuthenticated();
    }

    /**
     * Set the authentication token manually (e.g., from stored token)
     */
    setToken(token: string): void {
        this.client.setToken(token);
    }

    /**
     * Get the current authentication token
     */
    getToken(): string | null {
        return this.client.getToken();
    }

    /**
     * Clear the authentication token
     */
    clearToken(): void {
        this.client.clearToken();
    }
}

// ============================================================================
// Standalone Functions
// ============================================================================

const defaultAuth = new AuthApi();

/**
 * Register a new user
 */
export async function register(data: RegisterRequest): Promise<AuthResponse> {
    return defaultAuth.register(data);
}

/**
 * Login with email and password
 */
export async function login(data: LoginRequest): Promise<AuthResponse> {
    return defaultAuth.login(data);
}

/**
 * Logout the current user
 */
export async function logout(): Promise<LogoutResponse> {
    return defaultAuth.logout();
}

/**
 * Get the current authenticated user
 */
export async function getCurrentUser(): Promise<User> {
    return defaultAuth.getCurrentUser();
}

/**
 * Check if the user is authenticated
 */
export function isAuthenticated(): boolean {
    return defaultAuth.isAuthenticated();
}

/**
 * Set the authentication token
 */
export function setToken(token: string): void {
    defaultAuth.setToken(token);
}

/**
 * Get the current token
 */
export function getToken(): string | null {
    return defaultAuth.getToken();
}

/**
 * Clear the authentication token
 */
export function clearToken(): void {
    defaultAuth.clearToken();
}
