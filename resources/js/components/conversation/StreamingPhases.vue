<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Bot, Loader2, Brain } from 'lucide-vue-next';
import {
    ToolResultDefault,
    WebSearchResult,
    WebFetchResult,
    BashResult,
    FileReadResult,
    FileWriteResult,
} from '@/components/tools';

export interface StreamingPhase {
    type: 'thinking' | 'content' | 'tool_executing' | 'tool_completed';
    content: string;
    toolName?: string;
    toolCallId?: string;
    success?: boolean;
}

defineProps<{
    phases: StreamingPhase[];
    agentName: string;
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
    <div class="flex gap-3">
        <Avatar class="w-8 h-8 shrink-0">
            <AvatarFallback class="bg-secondary text-secondary-foreground">
                <Bot class="w-4 h-4" />
            </AvatarFallback>
        </Avatar>
        <div class="space-y-2 max-w-[85%] md:max-w-[65%]">
            <p class="font-medium text-muted-foreground text-xs">
                {{ agentName }}
            </p>

            <template v-for="(phase, idx) in phases" :key="idx">
                <!-- Thinking phase -->
                <details v-if="phase.type === 'thinking'" class="group" :open="idx === phases.length - 1">
                    <summary class="flex items-center gap-1.5 text-muted-foreground text-xs cursor-pointer list-none">
                        <Loader2 v-if="idx === phases.length - 1 && phase.type === 'thinking'" class="w-3.5 h-3.5 text-primary/60 animate-spin" />
                        <Brain v-else class="w-3.5 h-3.5 text-primary/60" />
                        <span>Thinking</span>
                        <span class="text-muted-foreground/60">({{ countWords(phase.content) }} words)</span>
                    </summary>
                    <div class="bg-primary/5 mt-2 p-3 border border-primary/10 rounded-lg text-muted-foreground text-sm markdown-content thinking-content">
                        <div v-html="renderMarkdown(phase.content)" />
                    </div>
                </details>

                <!-- Tool executing phase -->
                <div v-else-if="phase.type === 'tool_executing'" class="flex items-center gap-2 py-1">
                    <Badge variant="outline" class="text-xs">
                        <Loader2 class="mr-1 w-3 h-3 animate-spin" />
                        {{ phase.toolName }}
                    </Badge>
                </div>

                <!-- Tool completed phase - render result component -->
                <div v-else-if="phase.type === 'tool_completed'" class="bg-card shadow-sm px-4 py-2.5 border border-border rounded-2xl rounded-tl-md">
                    <component
                        :is="getToolResultComponent(phase.toolName)"
                        :content="phase.content"
                        :tool-name="phase.toolName"
                    />
                </div>

                <!-- Content phase -->
                <div v-else-if="phase.type === 'content' && phase.content?.trim()" class="bg-card shadow-sm px-4 py-2.5 border-primary/30 border-l-2 rounded-2xl rounded-tl-md">
                    <div class="text-sm markdown-content">
                        <span v-html="renderMarkdown(phase.content)" />
                        <span v-if="idx === phases.length - 1" class="streaming-cursor" />
                    </div>
                </div>
            </template>
        </div>
    </div>
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
</style>
