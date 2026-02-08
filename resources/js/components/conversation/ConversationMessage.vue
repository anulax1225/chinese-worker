<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Bot, ChevronRight, Wrench, Brain } from 'lucide-vue-next';
import {
    ToolResultDefault,
    WebSearchResult,
    WebFetchResult,
    BashResult,
    FileReadResult,
    FileWriteResult,
} from '@/components/tools';
import type { ChatMessage } from '@/types';

const props = defineProps<{
    message: ChatMessage;
    agentName: string;
    isFirstInSequence: boolean;
    renderMarkdown: (content: string) => string;
}>();

// Count words in thinking content
const countWords = (text: string): number => {
    return text.trim().split(/\s+/).filter(Boolean).length;
};

// Get the appropriate tool result component based on tool name
const getToolResultComponent = (toolName: string | undefined) => {
    const name = toolName?.toLowerCase() || '';
    if (name === 'web_search') return WebSearchResult;
    if (name === 'web_fetch') return WebFetchResult;
    if (name === 'bash') return BashResult;
    if (name === 'read') return FileReadResult;
    if (name === 'write') return FileWriteResult;
    return ToolResultDefault;
};
</script>

<template>
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

    <!-- Assistant message - Left aligned with avatar -->
    <div
        v-else-if="message.content?.trim() || message.thinking || message.tool_calls?.length"
        class="flex gap-3"
    >
        <!-- Avatar: only show on first message in sequence -->
        <Avatar v-if="isFirstInSequence" class="w-8 h-8 shrink-0">
            <AvatarFallback class="bg-secondary text-secondary-foreground">
                <Bot class="w-4 h-4" />
            </AvatarFallback>
        </Avatar>
        <div v-else class="w-8 shrink-0" />
        <div class="space-y-2 max-w-[85%] md:max-w-[65%]">
            <!-- Agent name: only show on first message in sequence -->
            <p v-if="isFirstInSequence" class="font-medium text-muted-foreground text-xs">
                {{ agentName }}
            </p>

            <!-- Thinking section -->
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

<style scoped>
/* Thinking content styling */
:deep(.thinking-content) {
    font-style: italic;
    opacity: 0.9;
}
</style>
