<script setup lang="ts">
import { ref, computed } from 'vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { FileText, ChevronDown, ChevronUp, Copy, Check } from 'lucide-vue-next';
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
        <div class="h-8 w-8 shrink-0 rounded-full bg-amber-500/10 flex items-center justify-center">
            <FileText class="h-4 w-4 text-amber-500" />
        </div>
        <Card class="flex-1 max-w-[85%] p-3 border-amber-500/30 bg-amber-500/5">
            <!-- Header -->
            <div class="flex items-center justify-between gap-2 mb-2">
                <div class="flex items-center gap-2">
                    <Badge variant="outline" class="bg-amber-500/10 text-amber-600 border-amber-500/30 text-xs">
                        read
                    </Badge>
                    <span class="text-xs text-muted-foreground">File Content</span>
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

            <!-- File content -->
            <div class="bg-muted/50 rounded-lg p-2 border border-border/50">
                <pre class="text-xs font-mono whitespace-pre-wrap overflow-x-auto">{{ displayContent }}</pre>
            </div>

            <!-- Expand/collapse button -->
            <button
                v-if="isLongContent"
                type="button"
                class="flex items-center gap-1 mt-2 text-xs text-muted-foreground hover:text-foreground transition-colors"
                @click="isExpanded = !isExpanded"
            >
                <ChevronUp v-if="isExpanded" class="h-3.5 w-3.5" />
                <ChevronDown v-else class="h-3.5 w-3.5" />
                {{ isExpanded ? 'Show less' : `Show ${hiddenLines} more lines` }}
            </button>
        </Card>
    </div>
</template>
