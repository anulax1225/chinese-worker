<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import MarkdownIt from 'markdown-it';
import { AppLayout } from '@/layouts';
import { Badge } from '@/components/ui/badge';
import { Card } from '@/components/ui/card';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Bot,
    ChevronRight,
    Terminal,
    Wrench,
    Loader2,
    ArrowDown,
    Sparkles,
    Brain,
} from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import { useConversationStream } from '@/composables/useConversationStream';
import ToolRequestDialog from '@/components/ToolRequestDialog.vue';
import ChatInputBar from '@/components/ChatInputBar.vue';
import {
    sendMessage as sendMessageAction,
    submitToolResult as submitToolResultAction,
} from '@/actions/App/Http/Controllers/Api/V1/ConversationController';
import type { Conversation, ChatMessage, ToolRequest } from '@/types';

const props = defineProps<{
    conversation: Conversation;
}>();

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
const { connectionState, connect, disconnect } = useConversationStream();

// State
const messagesContainer = ref<HTMLElement | null>(null);
const newMessage = ref('');
const isSubmitting = ref(false);
const streamingContent = ref('');
const streamingThinking = ref('');
const pendingToolRequest = ref<ToolRequest | null>(null);
const toolDialogOpen = ref(false);
const expandedThinking = ref<Set<number>>(new Set());
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

// Suggested prompts for empty state
const suggestedPrompts = computed(() => {
    const agentName = props.conversation.agent?.name?.toLowerCase() || '';

    // Default prompts
    const defaultPrompts = [
        'What can you help me with?',
        'Explain your capabilities',
        'Help me get started',
    ];

    // Agent-specific prompts based on name
    if (agentName.includes('code') || agentName.includes('dev')) {
        return [
            'Review my code for best practices',
            'Help me debug an issue',
            'Explain this code snippet',
        ];
    }

    if (agentName.includes('write') || agentName.includes('content')) {
        return [
            'Help me write a blog post',
            'Improve this paragraph',
            'Create an outline for...',
        ];
    }

    return defaultPrompts;
});

// Used for SSE connection logic
const isActive = computed(() =>
    props.conversation.status === 'active' ||
    props.conversation.status === 'waiting_tool'
);

// Can send messages if:
// - Status is active, waiting_tool, or completed (not failed/cancelled)
// - Client type is cli_web
const canSendMessages = computed(() => {
    const canContinue = props.conversation.status === 'active' ||
        props.conversation.status === 'waiting_tool' ||
        props.conversation.status === 'completed';
    const isWebClient = props.conversation.client_type === 'cli_web';
    return canContinue && isWebClient;
});

// Message to show when input is disabled
const inputDisabledReason = computed(() => {
    // Client type check takes priority
    if (props.conversation.client_type !== 'cli_web') {
        return `This conversation was started from ${props.conversation.client_type.toUpperCase()}`;
    }
    // Only failed/cancelled conversations block input
    if (props.conversation.status === 'failed') return 'Conversation failed';
    if (props.conversation.status === 'cancelled') return 'Conversation cancelled';
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

const triggerProcessing = async (content: string) => {
    isSubmitting.value = true;
    streamingContent.value = '';
    streamingThinking.value = '';

    try {
        const response = await fetch(sendMessageAction.url(props.conversation.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ content }),
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

const sendMessage = async (message: string) => {
    if (!message.trim() || isSubmitting.value) return;

    // Optimistically add user message
    props.conversation.messages.push({
        role: 'user',
        content: message,
    });

    await scrollToBottom();

    // Trigger processing via API
    await triggerProcessing(message);
};

const connectToStream = () => {
    connect(props.conversation.id, {
        onTextChunk: (chunk, type) => {
            if (type === 'thinking') {
                streamingThinking.value += chunk;
            } else {
                streamingContent.value += chunk;
            }
            scrollToBottom();
        },
        onToolRequest: (request) => {
            pendingToolRequest.value = request;
            toolDialogOpen.value = true;
            isSubmitting.value = false;
        },
        onCompleted: () => {
            // Refresh conversation to get final state
            router.reload({ only: ['conversation'] });
            streamingContent.value = '';
            streamingThinking.value = '';
            isSubmitting.value = false;
        },
        onFailed: (error) => {
            props.conversation.messages.push({
                role: 'system',
                content: `Error: ${error}`,
            });
            streamingContent.value = '';
            streamingThinking.value = '';
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

const deleteConversation = () => {
    if (confirm('Are you sure you want to delete this conversation?')) {
        router.delete(`/conversations/${props.conversation.id}`);
    }
};

const renderMarkdown = (content: string): string => {
    return md.render(content);
};

// Count words in thinking content
const countWords = (text: string): number => {
    return text.trim().split(/\s+/).filter(Boolean).length;
};

// Copy code to clipboard
const copyToClipboard = async (text: string) => {
    try {
        await navigator.clipboard.writeText(text);
        toast.success('Copied to clipboard');
    } catch {
        toast.error('Failed to copy');
    }
};

const toggleThinking = (index: number) => {
    if (expandedThinking.value.has(index)) {
        expandedThinking.value.delete(index);
    } else {
        expandedThinking.value.add(index);
    }
};

const formatTime = (date: string | null) => {
    if (!date) return '';
    return new Date(date).toLocaleTimeString('en-US', {
        hour: '2-digit',
        minute: '2-digit',
    });
};

// On mount: check if there's a pending user message that needs processing
onMounted(async () => {
    scrollToBottom();

    if (props.conversation.status === 'active') {
        const lastMessage = messages.value[messages.value.length - 1];
        if (lastMessage?.role === 'user') {
            const hasAssistantResponse = messages.value.length > 1 &&
                messages.value.some((m, i) => i > 0 && m.role === 'assistant');

            if (!hasAssistantResponse) {
                await triggerProcessing(lastMessage.content);
            }
        }
    } else if (props.conversation.status === 'waiting_tool') {
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
                class="h-full overflow-y-auto px-4 py-4 pb-44 relative"
                @scroll="handleScroll"
            >
                <!-- Empty state -->
                <div v-if="messages.length === 0" class="flex-1 flex items-center justify-center h-full">
                    <div class="text-center max-w-md">
                        <div class="h-16 w-16 rounded-full bg-primary/10 flex items-center justify-center mx-auto mb-4">
                            <Sparkles class="h-8 w-8 text-primary" />
                        </div>
                        <h3 class="text-lg font-medium mb-2">Start a conversation</h3>
                        <p class="text-sm text-muted-foreground mb-6">
                            Send a message to {{ conversation.agent?.name || 'the agent' }} to begin.
                        </p>
                        <!-- Suggested prompts -->
                        <div class="flex flex-col gap-2">
                            <button
                                v-for="prompt in suggestedPrompts"
                                :key="prompt"
                                type="button"
                                class="text-left px-4 py-3 rounded-lg border border-border bg-card hover:bg-accent hover:border-accent transition-colors text-sm"
                                @click="newMessage = prompt"
                            >
                                {{ prompt }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="space-y-4 max-w-7xl mx-auto">
                    <template v-for="(message, index) in messages" :key="index">
                        <!-- User message - Right aligned bubble -->
                        <div v-if="message.role === 'user'" class="flex justify-end">
                            <div class="max-w-[85%] md:max-w-[65%]">
                                <div class="bg-primary text-primary-foreground rounded-2xl px-4 py-2.5 shadow-sm">
                                    <div class="text-sm whitespace-pre-wrap">{{ message.content }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- Tool message - Accent colored card -->
                        <div v-else-if="message.role === 'tool'" class="flex gap-3">
                            <div class="h-8 w-8 shrink-0 rounded-full bg-info/10 flex items-center justify-center">
                                <Terminal class="h-4 w-4 text-info" />
                            </div>
                            <Card class="flex-1 max-w-[85%] p-3 border-info/30 bg-info/5">
                                <div class="flex items-center gap-2 mb-2">
                                    <Badge variant="outline" class="bg-info/10 text-info border-info/30 text-xs">
                                        {{ message.name || 'tool' }}
                                    </Badge>
                                    <span class="text-xs text-muted-foreground">Tool Result</span>
                                </div>
                                <pre class="text-xs font-mono whitespace-pre-wrap overflow-x-auto max-h-40 bg-muted/50 rounded p-2">{{ message.content }}</pre>
                            </Card>
                        </div>

                        <!-- System message - Centered, muted -->
                        <div v-else-if="message.role === 'system'" class="flex justify-center">
                            <p class="text-xs text-muted-foreground bg-muted/50 px-3 py-1 rounded-full">
                                {{ message.content }}
                            </p>
                        </div>

                        <!-- Assistant message - Left aligned with avatar (skip if empty content and no thinking/tools) -->
                        <div
                            v-else-if="message.content?.trim() || message.thinking || message.tool_calls?.length"
                            class="flex gap-3"
                        >
                            <!-- Avatar: only show on first message in sequence -->
                            <Avatar v-if="isFirstInSequence(index)" class="h-8 w-8 shrink-0">
                                <AvatarFallback class="bg-secondary text-secondary-foreground">
                                    <Bot class="h-4 w-4" />
                                </AvatarFallback>
                            </Avatar>
                            <div v-else class="w-8 shrink-0" />
                            <div class="max-w-[85%] md:max-w-[65%] space-y-2">
                                <!-- Agent name: only show on first message in sequence -->
                                <p v-if="isFirstInSequence(index)" class="text-xs font-medium text-muted-foreground">
                                    {{ conversation.agent?.name || 'Assistant' }}
                                </p>

                                <!-- Thinking section (card redesign) -->
                                <details v-if="message.thinking" class="group">
                                    <summary class="text-xs text-muted-foreground cursor-pointer flex items-center gap-1.5 list-none hover:text-foreground transition-colors">
                                        <Brain class="h-3.5 w-3.5 text-primary/60" />
                                        <span>Thinking</span>
                                        <span class="text-muted-foreground/60">({{ countWords(message.thinking) }} words)</span>
                                        <ChevronRight class="h-3 w-3 transition-transform group-open:rotate-90 ml-auto" />
                                    </summary>
                                    <div class="mt-2 p-3 rounded-lg bg-primary/5 border border-primary/10 text-sm text-muted-foreground markdown-content thinking-content">
                                        <div v-html="renderMarkdown(message.thinking)" />
                                    </div>
                                </details>

                                <!-- Content bubble (only show if content exists) -->
                                <div v-if="message.content?.trim()" class="bg-card border border-primary/30 rounded-2xl rounded-tl-md px-4 py-2.5 shadow-sm">
                                    <div class="text-sm markdown-content" v-html="renderMarkdown(message.content)" />
                                </div>

                                <!-- Tool calls indicator -->
                                <div v-if="message.tool_calls?.length" class="flex flex-wrap gap-1">
                                    <Badge
                                        v-for="tool in message.tool_calls"
                                        :key="tool.id"
                                        variant="outline"
                                        class="text-xs"
                                    >
                                        <Wrench class="h-3 w-3 mr-1" />
                                        {{ tool.function?.name || 'tool' }}
                                    </Badge>
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Streaming message -->
                    <div v-if="(streamingContent || streamingThinking) && isSubmitting" class="flex gap-3">
                        <Avatar class="h-8 w-8 shrink-0">
                            <AvatarFallback class="bg-secondary text-secondary-foreground">
                                <Bot class="h-4 w-4" />
                            </AvatarFallback>
                        </Avatar>
                        <div class="max-w-[85%] md:max-w-[65%] space-y-2">
                            <p class="text-xs font-medium text-muted-foreground">
                                {{ conversation.agent?.name || 'Assistant' }}
                            </p>

                            <!-- Streaming thinking -->
                            <details v-if="streamingThinking" class="group" open>
                                <summary class="text-xs text-muted-foreground cursor-pointer flex items-center gap-1.5 list-none">
                                    <Loader2 class="h-3.5 w-3.5 animate-spin text-primary/60" />
                                    <span>Thinking</span>
                                    <span class="text-muted-foreground/60">({{ countWords(streamingThinking) }} words)</span>
                                </summary>
                                <div class="mt-2 p-3 rounded-lg bg-primary/5 border border-primary/10 text-sm text-muted-foreground markdown-content thinking-content">
                                    <div v-html="renderMarkdown(streamingThinking)" />
                                </div>
                            </details>

                            <!-- Streaming content -->
                            <div v-if="streamingContent" class="bg-card border-l-2 border-primary/30 rounded-2xl rounded-tl-md px-4 py-2.5 shadow-sm">
                                <div class="text-sm markdown-content">
                                    <span v-html="renderMarkdown(streamingContent)" />
                                    <!-- Blinking cursor -->
                                    <span class="streaming-cursor" />
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Loading indicator (no streaming yet) -->
                    <div v-else-if="isSubmitting && !streamingContent && !streamingThinking" class="flex gap-3">
                        <Avatar class="h-8 w-8 shrink-0">
                            <AvatarFallback class="bg-secondary text-secondary-foreground">
                                <Bot class="h-4 w-4" />
                            </AvatarFallback>
                        </Avatar>
                        <div class="bg-card border-l-2 border-primary/30 rounded-2xl rounded-tl-md px-4 py-3 shadow-sm">
                            <div class="flex items-center gap-2">
                                <div class="flex gap-1">
                                    <div class="h-2 w-2 bg-primary/40 rounded-full animate-bounce" style="animation-delay: 0ms" />
                                    <div class="h-2 w-2 bg-primary/40 rounded-full animate-bounce" style="animation-delay: 150ms" />
                                    <div class="h-2 w-2 bg-primary/40 rounded-full animate-bounce" style="animation-delay: 300ms" />
                                </div>
                                <span class="text-xs text-muted-foreground">Thinking...</span>
                            </div>
                        </div>
                    </div>
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
                        class="absolute bottom-4 left-1/2 -translate-x-1/2 flex items-center gap-1.5 px-3 py-1.5 rounded-full bg-primary text-primary-foreground text-xs font-medium shadow-lg hover:bg-primary/90 transition-colors"
                        @click="scrollToBottomClick"
                    >
                        <ArrowDown class="h-3.5 w-3.5" />
                        New messages
                    </button>
                </Transition>
            </div>
        </div>

        <!-- Floating Chat Input Bar -->
        <ChatInputBar
            v-model:new-message="newMessage"
            :conversation="conversation"
            :is-submitting="isSubmitting"
            :can-send-messages="canSendMessages"
            :connection-state="connectionState"
            :input-disabled-reason="inputDisabledReason"
            @send="sendMessage"
            @stop="disconnect(); isSubmitting = false;"
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
/* Streaming cursor animation */
.streaming-cursor {
    display: inline-block;
    width: 2px;
    height: 1em;
    background: var(--foreground);
    margin-left: 1px;
    vertical-align: text-bottom;
    animation: blink 530ms steps(1) infinite;
}

@keyframes blink {
    0%, 100% { opacity: 1; }
    50% { opacity: 0; }
}

/* Thinking content styling */
:deep(.thinking-content) {
    font-style: italic;
    opacity: 0.9;
}

/* Markdown content styling */
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
