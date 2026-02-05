<script setup lang="ts">
import { ref, computed } from 'vue';
import { Search, ChevronDown, ChevronUp, ExternalLink, Globe } from 'lucide-vue-next';

interface SearchResult {
    title: string;
    url: string;
    snippet: string;
    engine: string;
    score: number;
    published_date?: string | null;
}

interface WebSearchData {
    query: string;
    results: SearchResult[];
    count: number;
    search_time_ms: number;
    from_cache: boolean;
}

const props = defineProps<{
    content: string;
    toolName?: string;
}>();

const isExpanded = ref(false);

const parsedData = computed<WebSearchData | null>(() => {
    try {
        return JSON.parse(props.content);
    } catch {
        return null;
    }
});

const getDomain = (url: string) => {
    try {
        return new URL(url).hostname.replace('www.', '');
    } catch {
        return url;
    }
};

const getFaviconUrl = (url: string) => {
    try {
        const domain = new URL(url).hostname;
        return `https://www.google.com/s2/favicons?domain=${domain}&sz=32`;
    } catch {
        return null;
    }
};

const uniqueSources = computed(() => {
    if (!parsedData.value?.results) return [];
    const seen = new Set<string>();
    return parsedData.value.results.filter(r => {
        const domain = getDomain(r.url);
        if (seen.has(domain)) return false;
        seen.add(domain);
        return true;
    }).slice(0, 5);
});
</script>

<template>
    <div class="max-w-[85%]">
        <!-- Compact header with search info -->
        <div class="flex items-center gap-2 mb-2">
            <div class="flex items-center gap-1.5 text-sm text-muted-foreground">
                <Search class="h-4 w-4" />
                <span>Searched</span>
                <span v-if="parsedData?.query" class="font-medium text-foreground">"{{ parsedData.query }}"</span>
            </div>
        </div>

        <!-- Source pills - ChatGPT/Claude style -->
        <div class="flex flex-wrap gap-1.5 mb-2">
            <a
                v-for="(source, index) in uniqueSources"
                :key="index"
                :href="source.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1.5 px-2 py-1 rounded-full bg-muted/60 hover:bg-muted border border-border/50 transition-colors text-xs"
            >
                <img
                    :src="getFaviconUrl(source.url)"
                    :alt="getDomain(source.url)"
                    class="h-3.5 w-3.5 rounded-sm"
                    @error="($event.target as HTMLImageElement).style.display = 'none'"
                />
                <Globe
                    v-if="!getFaviconUrl(source.url)"
                    class="h-3.5 w-3.5 text-muted-foreground"
                />
                <span class="text-muted-foreground max-w-[120px] truncate">{{ getDomain(source.url) }}</span>
            </a>
            <button
                v-if="parsedData?.results && parsedData.results.length > 0"
                type="button"
                class="inline-flex items-center gap-1 px-2 py-1 rounded-full bg-muted/60 hover:bg-muted border border-border/50 transition-colors text-xs text-muted-foreground"
                @click="isExpanded = !isExpanded"
            >
                <span>{{ parsedData.results.length }} sources</span>
                <ChevronUp v-if="isExpanded" class="h-3 w-3" />
                <ChevronDown v-else class="h-3 w-3" />
            </button>
        </div>

        <!-- Expanded results -->
        <Transition
            enter-active-class="transition-all duration-200 ease-out"
            leave-active-class="transition-all duration-150 ease-in"
            enter-from-class="opacity-0 max-h-0"
            enter-to-class="opacity-100 max-h-[500px]"
            leave-from-class="opacity-100 max-h-[500px]"
            leave-to-class="opacity-0 max-h-0"
        >
            <div v-if="isExpanded && parsedData?.results" class="overflow-hidden">
                <div class="space-y-2 pt-2 border-t border-border/50">
                    <a
                        v-for="(result, index) in parsedData.results"
                        :key="index"
                        :href="result.url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="block p-2.5 rounded-lg bg-muted/30 hover:bg-muted/60 border border-transparent hover:border-border/50 transition-all group"
                    >
                        <div class="flex items-start gap-2">
                            <img
                                :src="getFaviconUrl(result.url)"
                                :alt="getDomain(result.url)"
                                class="h-4 w-4 rounded-sm mt-0.5 shrink-0"
                                @error="($event.target as HTMLImageElement).style.display = 'none'"
                            />
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <h4 class="text-sm font-medium text-foreground group-hover:text-primary line-clamp-1 transition-colors">
                                        {{ result.title || 'Untitled' }}
                                    </h4>
                                    <ExternalLink class="h-3 w-3 text-muted-foreground opacity-0 group-hover:opacity-100 transition-opacity shrink-0" />
                                </div>
                                <p class="text-xs text-muted-foreground/80 truncate">
                                    {{ getDomain(result.url) }}
                                </p>
                                <p v-if="result.snippet" class="text-xs text-muted-foreground mt-1 line-clamp-2">
                                    {{ result.snippet }}
                                </p>
                            </div>
                        </div>
                    </a>
                </div>
            </div>
        </Transition>

        <!-- No results -->
        <div v-if="parsedData && !parsedData.results?.length" class="text-sm text-muted-foreground py-2">
            No results found
        </div>
    </div>
</template>
