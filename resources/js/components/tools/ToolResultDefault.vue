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

const MAX_COLLAPSED_LENGTH = 500;
const MAX_COLLAPSED_LINES = 10;

const formattedContent = computed(() => {
    try {
        const parsed = JSON.parse(props.content);
        return JSON.stringify(parsed, null, 2);
    } catch {
        return props.content;
    }
});

const isLongContent = computed(() => {
    const lines = formattedContent.value.split('\n').length;
    return formattedContent.value.length > MAX_COLLAPSED_LENGTH || lines > MAX_COLLAPSED_LINES;
});

const displayContent = computed(() => {
    if (!isLongContent.value || isExpanded.value) {
        return formattedContent.value;
    }
    const lines = formattedContent.value.split('\n');
    if (lines.length > MAX_COLLAPSED_LINES) {
        return lines.slice(0, MAX_COLLAPSED_LINES).join('\n') + '\n...';
    }
    return formattedContent.value.slice(0, MAX_COLLAPSED_LENGTH) + '...';
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
        <div class="h-8 w-8 shrink-0 rounded-full bg-info/10 flex items-center justify-center">
            <Terminal class="h-4 w-4 text-info" />
        </div>
        <Card class="flex-1 max-w-[85%] p-3 border-info/30 bg-info/5">
            <div class="flex items-center justify-between gap-2 mb-2">
                <div class="flex items-center gap-2">
                    <Badge variant="outline" class="bg-info/10 text-info border-info/30 text-xs">
                        {{ toolName || 'tool' }}
                    </Badge>
                    <span class="text-xs text-muted-foreground">Tool Result</span>
                </div>
                <button
                    type="button"
                    class="p-1 rounded hover:bg-muted/50 transition-colors"
                    @click="copyToClipboard"
                >
                    <Check v-if="copied" class="h-3.5 w-3.5 text-green-500" />
                    <Copy v-else class="h-3.5 w-3.5 text-muted-foreground" />
                </button>
            </div>
            <pre class="text-xs font-mono whitespace-pre-wrap overflow-x-auto bg-muted/50 rounded p-2">{{ displayContent }}</pre>
            <button
                v-if="isLongContent"
                type="button"
                class="flex items-center gap-1 mt-2 text-xs text-muted-foreground hover:text-foreground transition-colors"
                @click="isExpanded = !isExpanded"
            >
                <ChevronUp v-if="isExpanded" class="h-3.5 w-3.5" />
                <ChevronDown v-else class="h-3.5 w-3.5" />
                {{ isExpanded ? 'Show less' : 'Show more' }}
            </button>
        </Card>
    </div>
</template>
