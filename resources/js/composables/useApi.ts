/**
 * Composable for API Operations
 * Provides utilities for making API calls with reactive loading/error states
 */

import { ref, type Ref } from 'vue';
import { getClient, ApiException, ValidationError } from '@/sdk';

export interface UseApiOptions {
    /**
     * Initial loading state (default: false)
     */
    initialLoading?: boolean;
}

export interface UseApiReturn<T> {
    /**
     * Response data
     */
    data: Ref<T | null>;
    /**
     * Loading state
     */
    isLoading: Ref<boolean>;
    /**
     * Error message
     */
    error: Ref<string | null>;
    /**
     * Validation errors (for 422 responses)
     */
    validationErrors: Ref<Record<string, string[]>>;
    /**
     * Execute an API call
     */
    execute: <R = T>(fn: () => Promise<R>) => Promise<R | null>;
    /**
     * Reset state
     */
    reset: () => void;
}

/**
 * Generic composable for API operations with loading/error states
 */
export function useApi<T = unknown>(options: UseApiOptions = {}): UseApiReturn<T> {
    const { initialLoading = false } = options;
    const client = getClient();

    const data = ref<T | null>(null) as Ref<T | null>;
    const isLoading = ref(initialLoading);
    const error = ref<string | null>(null);
    const validationErrors = ref<Record<string, string[]>>({});

    const reset = () => {
        data.value = null;
        isLoading.value = false;
        error.value = null;
        validationErrors.value = {};
    };

    const execute = async <R = T>(fn: () => Promise<R>): Promise<R | null> => {
        isLoading.value = true;
        error.value = null;
        validationErrors.value = {};

        try {
            // Ensure CSRF cookie is set for session-based auth
            await client.ensureCsrf();

            const result = await fn();
            data.value = result as unknown as T;
            return result;
        } catch (err) {
            if (err instanceof ValidationError) {
                error.value = err.message;
                validationErrors.value = err.errors ?? {};
            } else if (err instanceof ApiException) {
                error.value = err.message;
            } else if (err instanceof Error) {
                error.value = err.message;
            } else {
                error.value = 'An unexpected error occurred';
            }
            return null;
        } finally {
            isLoading.value = false;
        }
    };

    return {
        data,
        isLoading,
        error,
        validationErrors,
        execute,
        reset,
    };
}

/**
 * Helper to get first validation error for a field
 */
export function getFieldError(
    errors: Record<string, string[]>,
    field: string,
): string | undefined {
    return errors[field]?.[0];
}

/**
 * Helper to check if a field has validation errors
 */
export function hasFieldError(
    errors: Record<string, string[]>,
    field: string,
): boolean {
    return !!errors[field]?.length;
}
