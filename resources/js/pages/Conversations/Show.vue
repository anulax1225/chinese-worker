<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, computed, nextTick, onMounted, watch } from 'vue';
import MarkdownIt from 'markdown-it';
import { AppLayout } from '@/layouts';
import { Badge } from '@/components/ui/badge';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Bot,
    ChevronRight,
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
    ToolResultDefault,
    WebSearchResult,
    WebFetchResult,
    BashResult,
    FileReadResult,
    FileWriteResult,
} from '@/components/tools';
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

// Streaming phase types
interface StreamingPhase {
    type: 'thinking' | 'content' | 'tool_executing' | 'tool_completed';
    content: string;
    toolName?: string;
    toolCallId?: string;
    success?: boolean;
}

// State
const messagesContainer = ref<HTMLElement | null>(null);
const newMessage = ref('');
const isSubmitting = ref(false);
const streamingPhases = ref<StreamingPhase[]>([]);
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
    streamingPhases.value = [];

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

// Get the appropriate tool result component based on tool name
const getToolResultComponent = (toolName: string | undefined) => {
    const name = toolName?.toLowerCase() || '';
    console.log("name", name);
    if (name === 'web_search') return WebSearchResult;
    if (name === 'web_fetch') return WebFetchResult;
    if (name === 'bash') return BashResult;
    if (name === 'read') return FileReadResult;
    if (name === 'write') return FileWriteResult;
    return ToolResultDefault;
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
                class="relative px-4 py-4 pb-44 h-full overflow-y-auto"
                @scroll="handleScroll"
            >
                <!-- Empty state -->
                <div v-if="messages.length === 0" class="flex flex-1 justify-center items-center h-full">
                    <div class="max-w-md text-center">
                        <div class="flex justify-center items-center bg-primary/10 mx-auto mb-4 rounded-full w-16 h-16">
                            <Sparkles class="w-8 h-8 text-primary" />
                        </div>
                        <h3 class="mb-2 font-medium text-lg">Start a conversation</h3>
                        <p class="mb-6 text-muted-foreground text-sm">
                            Send a message to {{ conversation.agent?.name || 'the agent' }} to begin.
                        </p>
                        <!-- Suggested prompts -->
                        <div class="flex flex-col gap-2">
                            <button
                                v-for="prompt in suggestedPrompts"
                                :key="prompt"
                                type="button"
                                class="bg-card hover:bg-accent px-4 py-3 border border-border hover:border-accent rounded-lg text-sm text-left transition-colors"
                                @click="newMessage = prompt"
                            >
                                {{ prompt }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else class="space-y-4 mx-auto max-w-7xl">
                    <template v-for="(message, index) in messages" :key="index">
                        <!-- User message - Right aligned bubble -->
                        <div v-if="message.role === 'user'" class="flex justify-end">
                            <div class="max-w-[85%] md:max-w-[65%]">
                                <div class="bg-primary shadow-sm px-4 py-2.5 rounded-2xl text-primary-foreground">
                                    <div class="text-sm whitespace-pre-wrap">{{ message.content }}</div>
                                </div>
                                <div v-if="message.token_count" class="mt-1 text-[10px] text-muted-foreground/50 text-right">
                                    {{ message.token_count }} tokens
                                </div>
                            </div>
                        </div>

                        <!-- Tool message - Dynamic component based on tool type -->
                        <div v-else-if="message.role === 'tool'" class="flex gap-3">
                            <div class="w-8 shrink-0" />
                            <div class="bg-card shadow-sm px-4 py-2.5 border border-border rounded-2xl rounded-tl-md max-w-[85%] md:max-w-[65%]">
                                <component
                                    :is="getToolResultComponent(message.name)"
                                    :content="message.content"
                                    :tool-name="message.name"
                                />
                            </div>
                        </div>

                        <!-- System message - Centered, muted -->
                        <div v-else-if="message.role === 'system'" class="flex justify-center">
                            <p class="bg-muted/50 px-3 py-1 rounded-full text-muted-foreground text-xs">
                                {{ message.content }}
                            </p>
                        </div>

                        <!-- Assistant message - Left aligned with avatar (skip if empty content and no thinking/tools) -->
                        <div
                            v-else-if="message.content?.trim() || message.thinking || message.tool_calls?.length"
                            class="flex gap-3"
                        >
                            <!-- Avatar: only show on first message in sequence -->
                            <Avatar v-if="isFirstInSequence(index)" class="w-8 h-8 shrink-0">
                                <AvatarFallback class="bg-secondary text-secondary-foreground">
                                    <Bot class="w-4 h-4" />
                                </AvatarFallback>
                            </Avatar>
                            <div v-else class="w-8 shrink-0" />
                            <div class="space-y-2 max-w-[85%] md:max-w-[65%]">
                                <!-- Agent name: only show on first message in sequence -->
                                <p v-if="isFirstInSequence(index)" class="font-medium text-muted-foreground text-xs">
                                    {{ conversation.agent?.name || 'Assistant' }}
                                </p>

                                <!-- Thinking section (card redesign) -->
                                <details v-if="message.thinking" class="group">
                                    <summary class="flex items-center gap-1.5 text-muted-foreground hover:text-foreground text-xs transition-colors cursor-pointer list-none">
                                        <Brain class="w-3.5 h-3.5 text-primary/60" />
                                        <span>Thinking</span>
                                        <span class="text-muted-foreground/60">({{ countWords(message.thinking) }} words)</span>
                                        <ChevronRight class="ml-auto w-3 h-3 group-open:rotate-90 transition-transform" />
                                    </summary>
                                    <div class="bg-primary/5 mt-2 p-3 border border-primary/10 rounded-lg text-muted-foreground text-sm markdown-content thinking-content">
                                        <div v-html="renderMarkdown(message.thinking)" />
                                    </div>
                                </details>

                                <!-- Content bubble (only show if content exists) -->
                                <div v-if="message.content?.trim()" class="bg-card shadow-sm px-4 py-2.5 border border-primary/30 rounded-2xl rounded-tl-md">
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
                                        <Wrench class="mr-1 w-3 h-3" />
                                        {{ tool.function?.name || 'tool' }}
                                    </Badge>
                                </div>

                                <!-- Token count -->
                                <div v-if="message.token_count" class="text-[10px] text-muted-foreground/50">
                                    {{ message.token_count }} tokens
                                </div>
                            </div>
                        </div>
                    </template>

                    <!-- Streaming phases -->
                    <div v-if="streamingPhases.length > 0 && isSubmitting" class="flex gap-3">
                        <Avatar class="w-8 h-8 shrink-0">
                            <AvatarFallback class="bg-secondary text-secondary-foreground">
                                <Bot class="w-4 h-4" />
                            </AvatarFallback>
                        </Avatar>
                        <div class="space-y-2 max-w-[85%] md:max-w-[65%]">
                            <p class="font-medium text-muted-foreground text-xs">
                                {{ conversation.agent?.name || 'Assistant' }}
                            </p>

                            <template v-for="(phase, idx) in streamingPhases" :key="idx">
                                <!-- Thinking phase -->
                                <details v-if="phase.type === 'thinking'" class="group" :open="idx === streamingPhases.length - 1">
                                    <summary class="flex items-center gap-1.5 text-muted-foreground text-xs cursor-pointer list-none">
                                        <Loader2 v-if="idx === streamingPhases.length - 1 && phase.type === 'thinking'" class="w-3.5 h-3.5 text-primary/60 animate-spin" />
                                        <Brain v-else class="w-3.5 h-3.5 text-primary/60" />
                                        <span>Thinking</span>
                                        <span class="text-muted-foreground/60">({{ countWords(phase.content) }} words)</span>
                                    </summary>
                                    <div class="bg-primary/5 mt-2 p-3 border border-primary/10 rounded-lg text-muted-foreground text-sm markdown-content thinking-content">
                                        <div v-html="renderMarkdown(phase.content)" />
                                    </div>
                                </details>

                                <!-- Tool executing phase -->
                                <div v-else-if="phase.type === 'tool_executing'"
                                     class="flex items-center gap-2 py-1">
                                    <Badge variant="outline" class="text-xs">
                                        <Loader2 class="mr-1 w-3 h-3 animate-spin" />
                                        {{ phase.toolName }}
                                    </Badge>
                                </div>

                                <!-- Tool completed phase - render result component -->
                                <div v-else-if="phase.type === 'tool_completed'"
                                     class="bg-card shadow-sm px-4 py-2.5 border border-border rounded-2xl rounded-tl-md">
                                    <component
                                        :is="getToolResultComponent(phase.toolName)"
                                        :content="phase.content"
                                        :tool-name="phase.toolName"
                                    />
                                </div>

                                <!-- Content phase -->
                                <div v-else-if="phase.type === 'content' && phase.content?.trim()"
                                     class="bg-card shadow-sm px-4 py-2.5 border-primary/30 border-l-2 rounded-2xl rounded-tl-md">
                                    <div class="text-sm markdown-content">
                                        <span v-html="renderMarkdown(phase.content)" />
                                        <span v-if="idx === streamingPhases.length - 1" class="streaming-cursor" />
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Loading indicator (no streaming yet) -->
                    <div v-else-if="isSubmitting && streamingPhases.length === 0" class="flex gap-3">
                        <Avatar class="w-8 h-8 shrink-0">
                            <AvatarFallback class="bg-secondary text-secondary-foreground">
                                <Bot class="w-4 h-4" />
                            </AvatarFallback>
                        </Avatar>
                        <div class="bg-card shadow-sm px-4 py-3 border-primary/30 border-l-2 rounded-2xl rounded-tl-md">
                            <div class="flex items-center gap-2">
                                <div class="flex gap-1">
                                    <div class="bg-primary/40 rounded-full w-2 h-2 animate-bounce" style="animation-delay: 0ms" />
                                    <div class="bg-primary/40 rounded-full w-2 h-2 animate-bounce" style="animation-delay: 150ms" />
                                    <div class="bg-primary/40 rounded-full w-2 h-2 animate-bounce" style="animation-delay: 300ms" />
                                </div>
                                <span class="text-muted-foreground text-xs">Thinking...</span>
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
