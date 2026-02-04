<script setup lang="ts">
import { ref, computed } from 'vue';
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
    <div class="fixed bottom-0 left-0 right-0 z-20 pb-4 pt-6 bg-gradient-to-t from-background via-background to-transparent pointer-events-none">
        <div class="max-w-3xl mx-auto px-4 pointer-events-auto">
            <!-- Main floating card -->
            <div class="bg-card border rounded-2xl shadow-lg overflow-hidden relative">
                <!-- Streaming indicator bar -->
                <div
                    v-if="connectionState === 'streaming'"
                    class="absolute top-0 left-0 right-0 h-0.5 streaming-bar"
                />

                <!-- Top row: Agent info + status -->
                <div class="flex items-center justify-between px-4 py-2 border-b bg-muted/30">
                    <div class="flex items-center gap-2 text-sm min-w-0">
                        <Avatar class="h-6 w-6 shrink-0">
                            <AvatarFallback class="bg-secondary text-secondary-foreground text-xs">
                                <Bot class="h-3 w-3" />
                            </AvatarFallback>
                        </Avatar>
                        <span class="font-medium truncate">{{ conversation.agent?.name || 'Agent' }}</span>
                        <div class="flex items-center gap-1.5 shrink-0">
                            <div :class="['h-1.5 w-1.5 rounded-full', connectionStatusColor]" />
                            <span class="text-xs text-muted-foreground hidden sm:inline">{{ connectionStatusText }}</span>
                        </div>
                        <Badge
                            v-if="conversation.status !== 'active'"
                            variant="secondary"
                            class="text-[10px] h-5 shrink-0"
                        >
                            {{ conversation.status }}
                        </Badge>
                    </div>

                    <!-- Actions -->
                    <div class="flex items-center gap-1 shrink-0">
                        <Button
                            v-if="isSubmitting"
                            variant="ghost"
                            size="sm"
                            class="h-7 gap-1 text-xs"
                            @click="$emit('stop')"
                        >
                            <Square class="h-3 w-3" />
                            <span class="hidden sm:inline">Stop</span>
                        </Button>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button variant="ghost" size="icon" class="h-7 w-7">
                                    <MoreVertical class="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    @click="$emit('delete')"
                                    class="text-destructive cursor-pointer"
                                >
                                    <Trash2 class="h-4 w-4 mr-2" />
                                    Delete conversation
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </div>
                </div>

                <!-- Input row -->
                <div class="flex items-end gap-2 p-3">
                    <Textarea
                        v-model="newMessage"
                        placeholder="Type a message..."
                        class="min-h-11 max-h-40 resize-none flex-1 border-0 shadow-none focus-visible:ring-0 bg-transparent"
                        :disabled="isSubmitting || !canSendMessages"
                        @keydown="handleKeydown"
                        rows="1"
                    />
                    <Button
                        type="button"
                        size="icon"
                        class="h-10 w-10 rounded-xl shrink-0"
                        :disabled="!newMessage.trim() || isSubmitting || !canSendMessages"
                        @click="sendMessage"
                    >
                        <Loader2 v-if="isSubmitting" class="h-4 w-4 animate-spin" />
                        <Send v-else class="h-4 w-4" />
                    </Button>
                </div>

                <!-- Bottom hint row -->
                <div class="px-4 pb-2 text-[10px] text-muted-foreground text-right">
                    <template v-if="canSendMessages">
                        <kbd class="px-1 py-0.5 bg-muted rounded">⌘</kbd>
                        <kbd class="px-1 py-0.5 bg-muted rounded ml-0.5">↵</kbd>
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
