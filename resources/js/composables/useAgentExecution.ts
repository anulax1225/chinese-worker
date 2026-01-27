/**
 * Composable for Agent Execution
 * Provides reactive state management for executing agents with streaming support
 */

import { ref, computed, onUnmounted } from 'vue';
import {
    getClient,
    AgentsApi,
    ExecutionsApi,
    ApiException,
    type Execution,
    type ExecuteAgentRequest,
} from '@/sdk';

export interface UseAgentExecutionOptions {
    /**
     * Enable streaming mode (default: false)
     */
    streaming?: boolean;
    /**
     * Poll interval for non-streaming execution status updates (default: 2000ms)
     */
    pollInterval?: number;
    /**
     * Timeout for waiting for execution completion (default: 300000ms / 5 minutes)
     */
    timeout?: number;
}

export function useAgentExecution(options: UseAgentExecutionOptions = {}) {
    const { streaming = false, pollInterval = 2000, timeout = 300000 } = options;

    const client = getClient();
    const agentsApi = new AgentsApi(client);
    const executionsApi = new ExecutionsApi(client);

    const execution = ref<Execution | null>(null);
    const isExecuting = ref(false);
    const streamContent = ref('');
    const error = ref<string | null>(null);

    let streamHandle: { close: () => void } | null = null;
    let pollTimer: ReturnType<typeof setTimeout> | null = null;

    const status = computed(() => execution.value?.status ?? null);
    const isComplete = computed(() => status.value === 'completed' || status.value === 'failed');
    const isSuccess = computed(() => status.value === 'completed');

    const stopStream = () => {
        if (streamHandle) {
            streamHandle.close();
            streamHandle = null;
        }
    };

    const stopPolling = () => {
        if (pollTimer) {
            clearTimeout(pollTimer);
            pollTimer = null;
        }
    };

    const reset = () => {
        stopStream();
        stopPolling();
        execution.value = null;
        isExecuting.value = false;
        streamContent.value = '';
        error.value = null;
    };

    const execute = async (agentId: number, request: ExecuteAgentRequest): Promise<Execution | null> => {
        reset();
        isExecuting.value = true;
        error.value = null;

        try {
            // Ensure CSRF cookie is set for session-based auth
            await client.ensureCsrf();

            if (streaming) {
                return await executeWithStreaming(agentId, request);
            } else {
                return await executeWithPolling(agentId, request);
            }
        } catch (err) {
            handleError(err);
            return null;
        } finally {
            isExecuting.value = false;
        }
    };

    const handleError = (err: unknown) => {
        if (err instanceof ApiException) {
            error.value = err.message;
        } else if (err instanceof Error) {
            error.value = err.message;
        } else {
            error.value = 'An unexpected error occurred';
        }
    };

    const executeWithStreaming = (agentId: number, request: ExecuteAgentRequest): Promise<Execution | null> => {
        return new Promise((resolve, reject) => {
            streamHandle = agentsApi.stream(agentId, request, {
                onChunk: (content) => {
                    streamContent.value += content;
                },
                onDone: async (executionId, _status) => {
                    try {
                        const exec = await executionsApi.get(executionId);
                        execution.value = exec;
                        resolve(exec);
                    } catch (err) {
                        reject(err);
                    }
                },
                onError: (message) => {
                    error.value = message;
                    reject(new Error(message));
                },
            });
        });
    };

    const executeWithPolling = async (agentId: number, request: ExecuteAgentRequest): Promise<Execution | null> => {
        // Start execution
        const exec = await agentsApi.execute(agentId, request);
        execution.value = exec;

        // If already complete, return
        if (exec.status === 'completed' || exec.status === 'failed') {
            return exec;
        }

        // Poll for completion
        const startTime = Date.now();

        const poll = async (): Promise<Execution> => {
            const updated = await executionsApi.get(exec.id);
            execution.value = updated;

            if (updated.status === 'completed' || updated.status === 'failed') {
                return updated;
            }

            if (Date.now() - startTime > timeout) {
                throw new Error(`Execution ${exec.id} timed out`);
            }

            return new Promise((resolve, reject) => {
                pollTimer = setTimeout(async () => {
                    try {
                        resolve(await poll());
                    } catch (err) {
                        reject(err);
                    }
                }, pollInterval);
            });
        };

        return await poll();
    };

    // Clean up on unmount
    onUnmounted(() => {
        stopStream();
        stopPolling();
    });

    return {
        execution,
        isExecuting,
        streamContent,
        error,
        status,
        isComplete,
        isSuccess,
        execute,
        stopStream,
        reset,
    };
}
