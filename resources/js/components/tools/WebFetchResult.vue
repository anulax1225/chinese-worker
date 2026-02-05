<script setup lang="ts">
import { ref, computed } from 'vue';
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Globe, ExternalLink, ChevronDown, ChevronUp, Clock, Database, Copy, Check } from 'lucide-vue-next';
import { toast } from 'vue-sonner';

interface WebFetchData {
    url: string;
    title: string;
    text: string;
    content_type: string;
    fetch_time_ms: number;
    from_cache: boolean;
    metadata?: Record<string, unknown>;
}

const props = defineProps<{
    content: string;
    toolName?: string;
}>();

const isExpanded = ref(false);
const copied = ref(false);
const MAX_COLLAPSED_LENGTH = 500;
const MAX_COLLAPSED_LINES = 10;

const parsedData = computed<WebFetchData | null>(() => {
    try {
        return JSON.parse(props.content);
    } catch {
        return null;
    }
});

const isLongContent = computed(() => {
    const text = parsedData.value?.text || '';
    const lines = text.split('\n').length;
    return text.length > MAX_COLLAPSED_LENGTH || lines > MAX_COLLAPSED_LINES;
});

const displayText = computed(() => {
    const text = parsedData.value?.text || '';
    if (!isLongContent.value || isExpanded.value) {
        return text;
    }
    const lines = text.split('\n');
    if (lines.length > MAX_COLLAPSED_LINES) {
        return lines.slice(0, MAX_COLLAPSED_LINES).join('\n') + '\n...';
    }
    return text.slice(0, MAX_COLLAPSED_LENGTH) + '...';
});

const formatUrl = (url: string) => {
    try {
        const parsed = new URL(url);
        return parsed.hostname;
    } catch {
        return url;
    }
};

const copyToClipboard = async () => {
    try {
        await navigator.clipboard.writeText(parsedData.value?.text || props.content);
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
        <div class="h-8 w-8 shrink-0 rounded-full bg-green-500/10 flex items-center justify-center">
            <Globe class="h-4 w-4 text-green-500" />
        </div>
        <Card class="flex-1 max-w-[85%] p-3 border-green-500/30 bg-green-500/5">
            <!-- Header -->
            <div class="flex items-center justify-between gap-2 mb-3">
                <div class="flex items-center gap-2">
                    <Badge variant="outline" class="bg-green-500/10 text-green-600 border-green-500/30 text-xs">
                        web_fetch
                    </Badge>
                    <span class="text-xs text-muted-foreground">Fetched Page</span>
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

            <!-- Title & URL -->
            <div v-if="parsedData" class="mb-3">
                <h4 v-if="parsedData.title" class="text-sm font-medium line-clamp-1">
                    {{ parsedData.title }}
                </h4>
                <a
                    v-if="parsedData.url"
                    :href="parsedData.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-xs text-muted-foreground hover:text-primary flex items-center gap-1 mt-0.5"
                >
                    {{ formatUrl(parsedData.url) }}
                    <ExternalLink class="h-3 w-3" />
                </a>
            </div>

            <!-- Content preview -->
            <div v-if="parsedData?.text" class="bg-muted/50 rounded-lg p-2">
                <pre class="text-xs font-mono whitespace-pre-wrap overflow-x-auto">{{ displayText }}</pre>
            </div>

            <!-- No content -->
            <div v-else-if="parsedData && !parsedData.text" class="text-sm text-muted-foreground">
                No content fetched
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
                {{ isExpanded ? 'Show less' : 'Show more' }}
            </button>

            <!-- Stats footer -->
            <div v-if="parsedData" class="flex flex-wrap items-center gap-3 mt-3 pt-2 border-t border-border/50">
                <span v-if="parsedData.content_type" class="text-[10px] text-muted-foreground">
                    {{ parsedData.content_type }}
                </span>
                <span class="text-[10px] text-muted-foreground flex items-center gap-1">
                    <Clock class="h-3 w-3" />
                    {{ parsedData.fetch_time_ms?.toFixed(0) || 0 }}ms
                </span>
                <span v-if="parsedData.from_cache" class="text-[10px] text-muted-foreground flex items-center gap-1">
                    <Database class="h-3 w-3" />
                    cached
                </span>
            </div>
        </Card>
    </div>
</template>
