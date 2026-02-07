import { ref, onUnmounted } from 'vue';
import type { ModelPullProgress } from '@/types';

export type PullState = 'idle' | 'connecting' | 'pulling' | 'completed' | 'failed';

export interface PullEventHandlers {
    onProgress?: (progress: ModelPullProgress) => void;
    onCompleted?: () => void;
    onFailed?: (error: string) => void;
}

export function useModelPull() {
    const pullState = ref<PullState>('idle');
    const progress = ref<ModelPullProgress | null>(null);
    const error = ref<string | null>(null);
    const eventSource = ref<EventSource | null>(null);

    function connect(streamUrl: string, handlers: PullEventHandlers = {}) {
        disconnect();

        pullState.value = 'connecting';
        error.value = null;

        const es = new EventSource(streamUrl);
        eventSource.value = es;

        es.addEventListener('connected', () => {
            pullState.value = 'pulling';
        });

        es.addEventListener('started', () => {
            pullState.value = 'pulling';
        });

        es.addEventListener('progress', (event) => {
            try {
                const data = JSON.parse(event.data) as ModelPullProgress;
                progress.value = data;
                handlers.onProgress?.(data);
            } catch (e) {
                console.error('Error parsing progress event:', e);
            }
        });

        es.addEventListener('completed', () => {
            pullState.value = 'completed';
            handlers.onCompleted?.();
            es.close();
        });

        es.addEventListener('failed', (event) => {
            pullState.value = 'failed';
            try {
                const data = JSON.parse(event.data);
                error.value = data.error || 'Unknown error';
                handlers.onFailed?.(error.value!);
            } catch (e) {
                error.value = 'Unknown error';
                handlers.onFailed?.('Unknown error');
            }
            es.close();
        });

        es.addEventListener('error', (event) => {
            pullState.value = 'failed';
            try {
                const data = JSON.parse((event as MessageEvent).data);
                error.value = data.message || 'Stream error';
            } catch {
                error.value = 'Connection error';
            }
            handlers.onFailed?.(error.value!);
            es.close();
        });

        es.onerror = () => {
            if (es.readyState === EventSource.CLOSED) {
                if (pullState.value !== 'completed') {
                    pullState.value = 'failed';
                    error.value = error.value || 'Connection closed unexpectedly';
                }
            }
        };
    }

    function disconnect() {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }
        pullState.value = 'idle';
        progress.value = null;
        error.value = null;
    }

    function reset() {
        disconnect();
    }

    onUnmounted(() => {
        disconnect();
    });

    return {
        pullState,
        progress,
        error,
        connect,
        disconnect,
        reset,
    };
}
