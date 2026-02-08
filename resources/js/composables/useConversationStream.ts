import { ref, onUnmounted } from 'vue';
import { stream } from '@/actions/App/Http/Controllers/Api/V1/ConversationController';
import type { ToolRequest } from '@/types';

export type ConnectionState = 'idle' | 'connecting' | 'connected' | 'streaming' | 'waiting_tool' | 'completed' | 'failed' | 'error';

export interface ToolExecution {
    call_id: string;
    name: string;
    arguments: Record<string, unknown>;
}

export interface StreamEventHandlers {
    onTextChunk?: (chunk: string, type: 'content' | 'thinking') => void;
    onToolExecuting?: (tool: ToolExecution) => void;
    onToolCompleted?: (callId: string, name: string, success: boolean, content: string) => void;
    onToolRequest?: (request: ToolRequest) => void;
    onCompleted?: (stats: { turns: number; tokens: number }) => void;
    onFailed?: (error: string) => void;
    onCancelled?: (stats: { turns: number; tokens: number }) => void;
    onStatusChanged?: (status: string) => void;
}

export function useConversationStream() {
    const connectionState = ref<ConnectionState>('idle');
    const eventSource = ref<EventSource | null>(null);

    function connect(conversationId: number, handlers: StreamEventHandlers) {
        // Close existing connection if any
        disconnect();

        connectionState.value = 'connecting';

        const es = new EventSource(stream.url(conversationId));
        eventSource.value = es;

        es.addEventListener('connected', () => {
            connectionState.value = 'connected';
        });

        es.addEventListener('text_chunk', (event) => {
            connectionState.value = 'streaming';
            try {
                const data = JSON.parse(event.data);
                const chunkType = data.type === 'thinking' ? 'thinking' : 'content';
                handlers.onTextChunk?.(data.chunk || data.content || '', chunkType);
            } catch (e) {
                console.error('Error parsing text_chunk event:', e);
            }
        });

        es.addEventListener('tool_executing', (event) => {
            connectionState.value = 'streaming';
            try {
                const data = JSON.parse(event.data);
                handlers.onToolExecuting?.(data.tool);
            } catch (e) {
                console.error('Error parsing tool_executing event:', e);
            }
        });

        es.addEventListener('tool_completed', (event) => {
            connectionState.value = 'streaming';
            try {
                const data = JSON.parse(event.data);
                handlers.onToolCompleted?.(data.call_id, data.name, data.success, data.content || '');
            } catch (e) {
                console.error('Error parsing tool_completed event:', e);
            }
        });

        es.addEventListener('tool_request', (event) => {
            connectionState.value = 'waiting_tool';
            try {
                const data = JSON.parse(event.data);
                handlers.onToolRequest?.(data.tool_request);
            } catch (e) {
                console.error('Error parsing tool_request event:', e);
            }
            // Close connection - client will reconnect after handling tool
            es.close();
        });

        es.addEventListener('completed', (event) => {
            connectionState.value = 'completed';
            try {
                const data = JSON.parse(event.data);
                handlers.onCompleted?.(data.stats || { turns: 0, tokens: 0 });
            } catch (e) {
                console.error('Error parsing completed event:', e);
            }
            es.close();
        });

        es.addEventListener('failed', (event) => {
            connectionState.value = 'failed';
            try {
                const data = JSON.parse(event.data);
                handlers.onFailed?.(data.error || 'Unknown error');
            } catch (e) {
                console.error('Error parsing failed event:', e);
            }
            es.close();
        });

        es.addEventListener('cancelled', (event) => {
            connectionState.value = 'idle';
            try {
                const data = JSON.parse(event.data);
                handlers.onCancelled?.(data.stats || { turns: 0, tokens: 0 });
            } catch (e) {
                console.error('Error parsing cancelled event:', e);
            }
            es.close();
        });

        es.addEventListener('status_changed', (event) => {
            try {
                const data = JSON.parse(event.data);
                handlers.onStatusChanged?.(data.status);
            } catch (e) {
                console.error('Error parsing status_changed event:', e);
            }
        });

        es.onerror = () => {
            // EventSource will try to reconnect automatically
            // Only set error state if connection is completely lost
            if (es.readyState === EventSource.CLOSED) {
                connectionState.value = 'error';
            }
        };
    }

    function disconnect() {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }
        connectionState.value = 'idle';
    }

    async function stop(conversationId: number) {
        // Close stream first
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }

        // Call stop API
        try {
            await fetch(`/api/v1/conversations/${conversationId}/stop`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                },
                credentials: 'include',
            });
        } catch (e) {
            console.error('Error stopping conversation:', e);
        }

        connectionState.value = 'idle';
    }

    // Clean up on unmount
    onUnmounted(() => {
        disconnect();
    });

    return {
        connectionState,
        connect,
        disconnect,
        stop,
    };
}

function getCsrfToken(): string {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}
