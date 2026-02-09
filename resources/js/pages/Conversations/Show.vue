<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import MarkdownIt from 'markdown-it';
import { AppLayout } from '@/layouts';
import { ArrowDown } from 'lucide-vue-next';
import { useConversationStream } from '@/composables/useConversationStream';
import ToolRequestDialog from '@/components/ToolRequestDialog.vue';
import ChatInputBar from '@/components/ChatInputBar.vue';
import {
    ConversationMessage,
    ConversationEmptyState,
    StreamingPhases,
    LoadingIndicator,
} from '@/components/conversation';
import type { StreamingPhase } from '@/components/conversation';
import {
    sendMessage as sendMessageAction,
    submitToolResult as submitToolResultAction,
} from '@/actions/App/Http/Controllers/Api/V1/ConversationController';
import type { Conversation, Document, ToolRequest } from '@/types';

const props = defineProps<{
    conversation: Conversation;
    documents: Document[];
}>();

// State for document selection
const selectedDocumentIds = ref<number[]>([]);

// Get CSRF token from meta tag
const getCsrfToken = (): string => {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
};

// Markdown renderer
const md = new MarkdownIt({
    html: false,
    linkify: true,
    breaks: true,
});

// SSE streaming composable
const { connectionState, connect, disconnect, stop } = useConversationStream();

// State
const messagesContainer = ref<HTMLElement | null>(null);
const newMessage = ref('');
const isSubmitting = ref(false);
const streamingPhases = ref<StreamingPhase[]>([]);
const pendingToolRequest = ref<ToolRequest | null>(null);
const toolDialogOpen = ref(false);
const showScrollPill = ref(false);
const isUserScrolledUp = ref(false);

const messages = computed(() => props.conversation.messages || []);

// Check if a message is the first in a sequence from the same role
const isFirstInSequence = (index: number) => {
    if (index === 0) return true;
    const currentRole = messages.value[index]?.role;
    const prevRole = messages.value[index - 1]?.role;
    return currentRole !== prevRole;
};

// Agent name for display
const agentName = computed(() => props.conversation.agent?.name || 'Assistant');

// Can send messages if client type is cli_web
// All statuses are allowed - user can resume failed/cancelled conversations
const canSendMessages = computed(() => {
    return props.conversation.client_type === 'cli_web';
});

// Message to show when input is disabled (informative, not blocking)
const inputDisabledReason = computed(() => {
    if (props.conversation.client_type !== 'cli_web') {
        return `This conversation was started from ${props.conversation.client_type.toUpperCase()}`;
    }
    if (props.conversation.status === 'failed') return 'Last request failed - send a message to retry';
    if (props.conversation.status === 'cancelled') return 'Stopped - send a message to continue';
    return '';
});

const scrollToBottom = async (force = false) => {
    await nextTick();
    if (messagesContainer.value) {
        // Only auto-scroll if user hasn't scrolled up (or force is true)
        if (force || !isUserScrolledUp.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
            showScrollPill.value = false;
        } else if (isSubmitting.value) {
            // Show pill if user is scrolled up during streaming
            showScrollPill.value = true;
        }
    }
};

const handleScroll = () => {
    if (!messagesContainer.value) return;

    const { scrollTop, scrollHeight, clientHeight } = messagesContainer.value;
    const distanceFromBottom = scrollHeight - scrollTop - clientHeight;

    // Consider user "scrolled up" if more than 100px from bottom
    isUserScrolledUp.value = distanceFromBottom > 100;

    // Hide pill if user scrolls to bottom
    if (!isUserScrolledUp.value) {
        showScrollPill.value = false;
    }
};

const scrollToBottomClick = () => {
    scrollToBottom(true);
    isUserScrolledUp.value = false;
};

const triggerProcessing = async (content: string, documentIds: number[] = []) => {
    isSubmitting.value = true;
    streamingPhases.value = [];

    try {
        const response = await fetch(sendMessageAction.url(props.conversation.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                content,
                document_ids: documentIds.length > 0 ? documentIds : undefined,
            }),
        });

        if (!response.ok) {
            const errorData = await response.json().catch(() => ({}));
            throw new Error(errorData.error || 'Failed to start processing');
        }

        // Processing started, connect to SSE stream
        connectToStream();
    } catch (error) {
        console.error('Error triggering processing:', error);
        props.conversation.messages.push({
            role: 'system',
            content: `Failed to process: ${error instanceof Error ? error.message : 'Unknown error'}`,
        });
        isSubmitting.value = false;
    }
};

const sendMessage = async (message: string, documentIds: number[] = []) => {
    if (!message.trim() || isSubmitting.value) return;

    // Build optimistic attachments from selected documents
    const optimisticAttachments = documentIds
        .map(id => props.documents.find(d => d.id === id))
        .filter(Boolean)
        .map(doc => ({
            id: `temp-${doc!.id}`,
            type: 'document' as const,
            document_id: doc!.id,
            filename: doc!.title || 'Untitled',
            mime_type: doc!.mime_type,
            storage_path: null,
            metadata: null,
            created_at: new Date().toISOString(),
        }));

    // Optimistically add user message
    props.conversation.messages.push({
        role: 'user',
        content: message,
        attachments: optimisticAttachments.length > 0 ? optimisticAttachments : undefined,
    });

    await scrollToBottom();

    // Trigger processing via API with document IDs
    await triggerProcessing(message, documentIds);
};

const connectToStream = () => {
    connect(props.conversation.id, {
        onTextChunk: (chunk, type) => {
            const phases = streamingPhases.value;
            const lastPhase = phases[phases.length - 1];

            // If no phase or different type, start new phase
            if (!lastPhase || lastPhase.type !== type) {
                phases.push({ type, content: chunk });
            } else {
                // Append to existing phase
                lastPhase.content += chunk;
            }
            scrollToBottom();
        },
        onToolExecuting: (tool) => {
            streamingPhases.value.push({
                type: 'tool_executing',
                content: '',
                toolName: tool.name,
                toolCallId: tool.call_id,
            });
            scrollToBottom();
        },
        onToolCompleted: (callId, name, success, content) => {
            // Find and update the tool_executing phase
            const toolPhase = streamingPhases.value.find(
                p => p.type === 'tool_executing' && p.toolCallId === callId
            );
            if (toolPhase) {
                toolPhase.type = 'tool_completed';
                toolPhase.success = success;
                toolPhase.content = content;
            }
        },
        onToolRequest: (request) => {
            pendingToolRequest.value = request;
            toolDialogOpen.value = true;
            isSubmitting.value = false;
        },
        onCompleted: () => {
            // Refresh conversation to get final state
            router.reload({ only: ['conversation'] });
            streamingPhases.value = [];
            isSubmitting.value = false;
        },
        onFailed: (error) => {
            props.conversation.messages.push({
                role: 'system',
                content: `Error: ${error}`,
            });
            streamingPhases.value = [];
            isSubmitting.value = false;
        },
        onStatusChanged: (status) => {
            console.log('Status changed:', status);
        },
    });
};

const handleToolApprove = async (callId: string) => {
    toolDialogOpen.value = false;
    isSubmitting.value = true;

    try {
        const response = await fetch(submitToolResultAction.url(props.conversation.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                call_id: callId,
                success: true,
                output: '',
                error: null,
            }),
        });

        if (!response.ok) {
            throw new Error('Failed to submit tool approval');
        }

        pendingToolRequest.value = null;
        connectToStream();
    } catch (error) {
        console.error('Error approving tool:', error);
        isSubmitting.value = false;
    }
};

const handleToolReject = async (callId: string, reason: string) => {
    toolDialogOpen.value = false;
    isSubmitting.value = true;

    try {
        const response = await fetch(submitToolResultAction.url(props.conversation.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                call_id: callId,
                success: false,
                output: '',
                error: reason || 'User rejected the tool request',
            }),
        });

        if (!response.ok) {
            throw new Error('Failed to submit tool rejection');
        }

        pendingToolRequest.value = null;
        connectToStream();
    } catch (error) {
        console.error('Error rejecting tool:', error);
        isSubmitting.value = false;
    }
};

const handleToolDialogClose = () => {
    toolDialogOpen.value = false;
};

const handleStop = async () => {
    await stop(props.conversation.id);
    streamingPhases.value = [];
    isSubmitting.value = false;
};

const deleteConversation = () => {
    if (confirm('Are you sure you want to delete this conversation?')) {
        router.delete(`/conversations/${props.conversation.id}`);
    }
};

const renderMarkdown = (content: string): string => {
    return md.render(content);
};

// On mount: handle initial state
onMounted(async () => {
    scrollToBottom();

    // If waiting for tool approval, show the dialog (only for web clients)
    if (
        props.conversation.status === 'waiting_tool' &&
        props.conversation.pending_tool_request &&
        props.conversation.client_type === 'cli_web'
    ) {
        pendingToolRequest.value = props.conversation.pending_tool_request;
        toolDialogOpen.value = true;
    }

    // Auto-connect to stream if conversation is currently processing
    if (props.conversation.status === 'active') {
        isSubmitting.value = true;
        connectToStream();
    }
});

watch(() => props.conversation.status, (newStatus) => {
    if (newStatus === 'completed' || newStatus === 'failed') {
        disconnect();
    }
});
</script>

<template>
    <AppLayout :title="`Chat with ${conversation.agent?.name || 'Agent'}`">
        <div class="h-[calc(100vh-3.5rem)]">
            <!-- Messages Area -->
            <div
                ref="messagesContainer"
                class="relative px-4 py-4 pb-44 h-full overflow-y-auto"
                @scroll="handleScroll"
            >
                <!-- Empty state -->
                <ConversationEmptyState
                    v-if="messages.length === 0"
                    :agent-name="agentName"
                    @select-prompt="newMessage = $event"
                />

                <div v-else class="space-y-4 mx-auto max-w-7xl">
                    <!-- Message list -->
                    <ConversationMessage
                        v-for="(message, index) in messages"
                        :key="index"
                        :message="message"
                        :agent-name="agentName"
                        :is-first-in-sequence="isFirstInSequence(index)"
                        :render-markdown="renderMarkdown"
                    />

                    <!-- Streaming phases -->
                    <StreamingPhases
                        v-if="streamingPhases.length > 0 && isSubmitting"
                        :phases="streamingPhases"
                        :agent-name="agentName"
                        :render-markdown="renderMarkdown"
                    />

                    <!-- Loading indicator (no streaming yet) -->
                    <LoadingIndicator v-else-if="isSubmitting && streamingPhases.length === 0" />
                </div>

                <!-- Scroll to bottom pill -->
                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    leave-active-class="transition-all duration-150 ease-in"
                    enter-from-class="opacity-0 translate-y-2"
                    leave-to-class="opacity-0 translate-y-2"
                >
                    <button
                        v-if="showScrollPill"
                        type="button"
                        class="bottom-4 left-1/2 absolute flex items-center gap-1.5 bg-primary hover:bg-primary/90 shadow-lg px-3 py-1.5 rounded-full font-medium text-primary-foreground text-xs transition-colors -translate-x-1/2"
                        @click="scrollToBottomClick"
                    >
                        <ArrowDown class="w-3.5 h-3.5" />
                        New messages
                    </button>
                </Transition>
            </div>
        </div>

        <!-- Floating Chat Input Bar -->
        <ChatInputBar
            v-model:new-message="newMessage"
            v-model:selected-document-ids="selectedDocumentIds"
            :conversation="conversation"
            :is-submitting="isSubmitting"
            :can-send-messages="canSendMessages"
            :connection-state="connectionState"
            :input-disabled-reason="inputDisabledReason"
            :available-documents="documents"
            @send="sendMessage"
            @stop="handleStop"
            @delete="deleteConversation"
        />

        <!-- Tool Request Dialog -->
        <ToolRequestDialog
            :tool-request="pendingToolRequest"
            :is-open="toolDialogOpen"
            :is-submitting="isSubmitting"
            @approve="handleToolApprove"
            @reject="handleToolReject"
            @close="handleToolDialogClose"
        />
    </AppLayout>
</template>

<style scoped>
/* Markdown content styling - applies to child components */
:deep(.markdown-content) {
    line-height: 1.625;
}

:deep(.markdown-content) p {
    margin-bottom: 0.75rem;
}

:deep(.markdown-content) p:last-child {
    margin-bottom: 0;
}

:deep(.markdown-content) h1,
:deep(.markdown-content) h2,
:deep(.markdown-content) h3,
:deep(.markdown-content) h4,
:deep(.markdown-content) h5,
:deep(.markdown-content) h6 {
    font-weight: 600;
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

:deep(.markdown-content) h1:first-child,
:deep(.markdown-content) h2:first-child,
:deep(.markdown-content) h3:first-child {
    margin-top: 0;
}

:deep(.markdown-content) h1 { font-size: 1.125rem; }
:deep(.markdown-content) h2 { font-size: 1rem; }
:deep(.markdown-content) h3,
:deep(.markdown-content) h4,
:deep(.markdown-content) h5,
:deep(.markdown-content) h6 { font-size: 0.875rem; }

/* Code blocks - Enhanced styling */
:deep(.markdown-content) pre {
    position: relative;
    margin: 0.75rem 0;
    padding: 0.75rem;
    padding-top: 2rem;
    border-radius: 0.5rem;
    background: var(--zinc-900, #18181b);
    border: 1px solid var(--border);
    overflow-x: auto;
    font-size: 0.75rem;
}

/* Dark mode code blocks */
:root.dark :deep(.markdown-content) pre {
    background: oklch(from var(--background) calc(l - 0.05) c h);
}

:deep(.markdown-content) pre::before {
    content: 'code';
    position: absolute;
    top: 0.375rem;
    left: 0.75rem;
    font-size: 0.625rem;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--muted-foreground);
    opacity: 0.7;
}

:deep(.markdown-content) pre code {
    background: transparent;
    padding: 0;
    font-size: 0.75rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
    border: none;
    color: var(--zinc-100, #f4f4f5);
}

/* Inline code */
:deep(.markdown-content) code {
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    background: var(--muted);
    border: 1px solid var(--border);
    font-size: 0.75rem;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

/* Lists */
:deep(.markdown-content) ul,
:deep(.markdown-content) ol {
    margin: 0.5rem 0;
    padding-left: 1.25rem;
}

:deep(.markdown-content) ul { list-style-type: disc; }
:deep(.markdown-content) ol { list-style-type: decimal; }

:deep(.markdown-content) li {
    margin-bottom: 0.25rem;
}

:deep(.markdown-content) li > ul,
:deep(.markdown-content) li > ol {
    margin: 0.25rem 0;
}

/* Blockquotes */
:deep(.markdown-content) blockquote {
    margin: 0.75rem 0;
    padding-left: 1rem;
    border-left: 2px solid var(--primary);
    color: var(--muted-foreground);
    font-style: italic;
    background: var(--muted);
    padding: 0.5rem 1rem;
    border-radius: 0 0.25rem 0.25rem 0;
}

/* Links */
:deep(.markdown-content) a {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}

:deep(.markdown-content) a:hover {
    opacity: 0.8;
}

/* Horizontal rules */
:deep(.markdown-content) hr {
    margin: 1rem 0;
    border-color: var(--border);
}

/* Tables */
:deep(.markdown-content) table {
    margin: 0.75rem 0;
    width: 100%;
    border-collapse: collapse;
    font-size: 0.75rem;
}

:deep(.markdown-content) th,
:deep(.markdown-content) td {
    border: 1px solid var(--border);
    padding: 0.375rem 0.5rem;
    text-align: left;
}

:deep(.markdown-content) th {
    background: var(--muted);
    font-weight: 500;
}

/* Strong and emphasis */
:deep(.markdown-content) strong {
    font-weight: 600;
}

:deep(.markdown-content) em {
    font-style: italic;
}

/* Images */
:deep(.markdown-content) img {
    margin: 0.5rem 0;
    max-width: 100%;
    border-radius: 0.375rem;
}
</style>
