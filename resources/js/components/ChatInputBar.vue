<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Badge } from '@/components/ui/badge';
import {
    Send,
    Bot,
    Square,
    MoreVertical,
    Trash2,
    Loader2,
} from 'lucide-vue-next';
import TokenUsageIndicator from '@/components/TokenUsageIndicator.vue';
import type { Conversation } from '@/types';
import type { ConnectionState } from '@/composables/useConversationStream';

const props = defineProps<{
    conversation: Conversation;
    isSubmitting: boolean;
    canSendMessages: boolean;
    connectionState: ConnectionState;
    inputDisabledReason: string;
}>();

const emit = defineEmits<{
    send: [message: string];
    stop: [];
    delete: [];
}>();

const newMessage = defineModel<string>('newMessage', { default: '' });

const connectionStatusColor = computed(() => {
    const colors: Record<ConnectionState, string> = {
        idle: 'bg-muted-foreground/50',
        connecting: 'bg-warning',
        connected: 'bg-success',
        streaming: 'bg-success animate-pulse',
        waiting_tool: 'bg-amber-500',
        completed: 'bg-success',
        failed: 'bg-destructive',
        error: 'bg-destructive',
    };
    return colors[props.connectionState];
});

const connectionStatusText = computed(() => {
    const statusMap: Record<ConnectionState, string> = {
        idle: 'Ready',
        connecting: 'Connecting...',
        connected: 'Connected',
        streaming: 'Streaming',
        waiting_tool: 'Awaiting approval',
        completed: 'Completed',
        failed: 'Failed',
        error: 'Error',
    };
    return statusMap[props.connectionState];
});

const handleKeydown = (e: KeyboardEvent) => {
    // Cmd/Ctrl + Enter to send
    if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
        e.preventDefault();
        sendMessage();
    }
};

const sendMessage = () => {
    if (!newMessage.value.trim() || props.isSubmitting) return;
    emit('send', newMessage.value.trim());
    newMessage.value = '';
};
</script>

<template>
    <div class="right-0 bottom-0 left-0 z-20 fixed bg-gradient-to-t from-background via-background to-transparent mx-auto pt-6 pb-4 pointer-events-none">
        <div class="mx-auto px-4 max-w-3xl pointer-events-auto">
            <!-- Main floating card -->
            <div class="relative bg-card shadow-lg border rounded-2xl overflow-hidden">
                <!-- Streaming indicator bar -->
                <div
                    v-if="connectionState === 'streaming'"
                    class="top-0 right-0 left-0 absolute h-0.5 streaming-bar"
                />

                <!-- Top row: Agent info + status -->
                <div class="flex justify-between items-center bg-muted/30 px-4 py-2 border-b">
                    <div class="flex items-center gap-2 min-w-0 text-sm">
                        <Avatar class="w-6 h-6 shrink-0">
                            <AvatarFallback class="bg-secondary text-secondary-foreground text-xs">
                                <Bot class="w-3 h-3" />
                            </AvatarFallback>
                        </Avatar>
                        <span class="font-medium truncate">{{ conversation.agent?.name || 'Agent' }}</span>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <div :class="['h-1.5 w-1.5 rounded-full', connectionStatusColor]" />
                            <span class="hidden sm:inline text-muted-foreground text-xs">{{ connectionStatusText }}</span>
                        </div>
                        <Badge
                            v-if="conversation.status !== 'active'"
                            variant="secondary"
                            class="h-5 text-[10px] shrink-0"
                        >
                            {{ conversation.status }}
                        </Badge>
                    </div>

                    <!-- Token usage + Actions -->
                    <div class="flex items-center gap-3 shrink-0">
                        <!-- Token Usage Indicator -->
                        <TokenUsageIndicator
                            v-if="conversation.token_usage"
                            :token-usage="conversation.token_usage"
                            compact
                            class="hidden sm:flex"
                        />

                        <!-- Actions -->
                        <div class="flex items-center gap-1">
                            <Button
                                v-if="isSubmitting"
                                variant="ghost"
                                size="sm"
                                class="gap-1 h-7 text-xs"
                                @click="$emit('stop')"
                            >
                                <Square class="w-3 h-3" />
                                <span class="hidden sm:inline">Stop</span>
                            </Button>
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button variant="ghost" size="icon" class="w-7 h-7">
                                        <MoreVertical class="w-4 h-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="$emit('delete')"
                                        class="text-destructive cursor-pointer"
                                    >
                                        <Trash2 class="mr-2 w-4 h-4" />
                                        Delete conversation
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                        </div>
                    </div>
                </div>

                <!-- Input row -->
                <div class="flex items-end gap-2 p-3">
                    <Textarea
                        v-model="newMessage"
                        placeholder="Type a message..."
                        class="flex-1 bg-transparent shadow-none border-0 focus-visible:ring-0 min-h-11 max-h-40 resize-none"
                        :disabled="isSubmitting || !canSendMessages"
                        @keydown="handleKeydown"
                        rows="1"
                    />
                    <Button
                        type="button"
                        size="icon"
                        class="rounded-xl w-10 h-10 shrink-0"
                        :disabled="!newMessage.trim() || isSubmitting || !canSendMessages"
                        @click="sendMessage"
                    >
                        <Loader2 v-if="isSubmitting" class="w-4 h-4 animate-spin" />
                        <Send v-else class="w-4 h-4" />
                    </Button>
                </div>

                <!-- Bottom hint row -->
                <div class="px-4 pb-2 text-[10px] text-muted-foreground text-right">
                    <template v-if="canSendMessages">
                        <kbd class="bg-muted px-1 py-0.5 rounded">⌘</kbd>
                        <kbd class="bg-muted ml-0.5 px-1 py-0.5 rounded">↵</kbd>
                        <span class="ml-1">to send</span>
                    </template>
                    <template v-else>
                        {{ inputDisabledReason }}
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Streaming indicator bar */
.streaming-bar {
    background: linear-gradient(
        90deg,
        transparent 0%,
        var(--primary) 50%,
        transparent 100%
    );
    background-size: 200% 100%;
    animation: shimmer 1.5s ease-in-out infinite;
}

@keyframes shimmer {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}
</style>
