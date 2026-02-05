<script setup lang="ts">
import { ref, computed } from 'vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Terminal, ChevronDown, ChevronUp, Copy, Check } from 'lucide-vue-next';
import { toast } from 'vue-sonner';

const props = defineProps<{
    content: string;
    toolName?: string;
}>();

const isExpanded = ref(false);
const copied = ref(false);
const MAX_COLLAPSED_LINES = 10;

const lines = computed(() => props.content.split('\n'));

const isLongContent = computed(() => {
    return lines.value.length > MAX_COLLAPSED_LINES;
});

const displayContent = computed(() => {
    if (!isLongContent.value || isExpanded.value) {
        return props.content;
    }
    return lines.value.slice(0, MAX_COLLAPSED_LINES).join('\n') + '\n...';
});

const hiddenLines = computed(() => {
    return lines.value.length - MAX_COLLAPSED_LINES;
});

const copyToClipboard = async () => {
    try {
        await navigator.clipboard.writeText(props.content);
        copied.value = true;
        toast.success('Copied to clipboard');
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        toast.error('Failed to copy');
    }
};
</script>

<template>
    <div class="flex gap-3">
        <div class="h-8 w-8 shrink-0 rounded-full bg-zinc-700 flex items-center justify-center">
            <Terminal class="h-4 w-4 text-zinc-300" />
        </div>
        <Card class="flex-1 max-w-[85%] p-0 border-zinc-700 bg-zinc-900 overflow-hidden">
            <!-- Header -->
            <div class="flex items-center justify-between gap-2 px-3 py-2 bg-zinc-800 border-b border-zinc-700">
                <div class="flex items-center gap-2">
                    <div class="flex gap-1.5">
                        <div class="h-2.5 w-2.5 rounded-full bg-red-500/80" />
                        <div class="h-2.5 w-2.5 rounded-full bg-yellow-500/80" />
                        <div class="h-2.5 w-2.5 rounded-full bg-green-500/80" />
                    </div>
                    <Badge variant="outline" class="bg-zinc-700/50 text-zinc-300 border-zinc-600 text-xs">
                        bash
                    </Badge>
                </div>
                <button
                    type="button"
                    class="p-1 rounded hover:bg-zinc-700 transition-colors"
                    @click="copyToClipboard"
                >
                    <Check v-if="copied" class="h-3.5 w-3.5 text-green-400" />
                    <Copy v-else class="h-3.5 w-3.5 text-zinc-400" />
                </button>
            </div>

            <!-- Terminal output -->
            <div class="p-3">
                <pre class="text-xs font-mono whitespace-pre-wrap overflow-x-auto text-zinc-100">{{ displayContent }}</pre>
            </div>

            <!-- Expand/collapse button -->
            <div v-if="isLongContent" class="px-3 pb-2">
                <button
                    type="button"
                    class="flex items-center gap-1 text-xs text-zinc-400 hover:text-zinc-200 transition-colors"
                    @click="isExpanded = !isExpanded"
                >
                    <ChevronUp v-if="isExpanded" class="h-3.5 w-3.5" />
                    <ChevronDown v-else class="h-3.5 w-3.5" />
                    {{ isExpanded ? 'Show less' : `Show ${hiddenLines} more lines` }}
                </button>
            </div>
        </Card>
    </div>
</template>
