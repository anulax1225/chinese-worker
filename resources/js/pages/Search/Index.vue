<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Search,
    Loader2,
    Clock,
    Database,
    ExternalLink,
    AlertCircle,
    CheckCircle2,
    XCircle,
} from 'lucide-vue-next';

interface SearchResult {
    title: string;
    url: string;
    snippet: string;
    engine: string | null;
    score: number | null;
    published_date: string | null;
}

const props = defineProps<{
    query: string;
    maxResults: number;
    engines: string;
    results: SearchResult[] | null;
    error: string | null;
    searchTime: number | null;
    fromCache: boolean;
    serviceAvailable: boolean;
}>();

const form = useForm({
    query: props.query,
    max_results: props.maxResults.toString(),
    engines: props.engines,
});

const submit = () => {
    form.post('/search', {
        preserveScroll: true,
    });
};

const getEngineBadgeClass = (engine: string | null) => {
    if (!engine) return 'bg-gray-500/10 text-gray-600 border-gray-500/20';
    const colors: Record<string, string> = {
        google: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        bing: 'bg-teal-500/10 text-teal-600 border-teal-500/20',
        duckduckgo: 'bg-orange-500/10 text-orange-600 border-orange-500/20',
        wikipedia: 'bg-gray-500/10 text-gray-600 border-gray-500/20',
    };
    return colors[engine.toLowerCase()] || 'bg-purple-500/10 text-purple-600 border-purple-500/20';
};

const truncateUrl = (url: string, maxLength = 60) => {
    if (url.length <= maxLength) return url;
    return url.substring(0, maxLength) + '...';
};
</script>

<template>
    <AppLayout title="Search">
        <div class="max-w-4xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">Search Test</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        Test the SearXNG search integration
                    </p>
                </div>
                <Badge
                    :variant="serviceAvailable ? 'default' : 'destructive'"
                    class="flex items-center gap-1.5"
                >
                    <component
                        :is="serviceAvailable ? CheckCircle2 : XCircle"
                        class="h-3.5 w-3.5"
                    />
                    {{ serviceAvailable ? 'Service Available' : 'Service Unavailable' }}
                </Badge>
            </div>

            <!-- Search Form -->
            <form @submit.prevent="submit" class="mb-8">
                <div class="bg-card border rounded-xl p-6 space-y-4">
                    <div class="space-y-2">
                        <Label for="query">Search Query</Label>
                        <div class="relative">
                            <Search class="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
                            <Input
                                id="query"
                                v-model="form.query"
                                placeholder="Enter your search query..."
                                class="pl-9"
                                :disabled="form.processing"
                            />
                        </div>
                        <p v-if="form.errors.query" class="text-sm text-destructive">
                            {{ form.errors.query }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <Label for="max_results">Max Results</Label>
                            <Select v-model="form.max_results" :disabled="form.processing">
                                <SelectTrigger id="max_results">
                                    <SelectValue placeholder="Select max results" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="5">5 results</SelectItem>
                                    <SelectItem value="10">10 results</SelectItem>
                                    <SelectItem value="20">20 results</SelectItem>
                                    <SelectItem value="50">50 results</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div class="space-y-2">
                            <Label for="engines">Engines (optional)</Label>
                            <Input
                                id="engines"
                                v-model="form.engines"
                                placeholder="google, bing, duckduckgo"
                                :disabled="form.processing"
                            />
                            <p class="text-xs text-muted-foreground">
                                Comma-separated list of engines
                            </p>
                        </div>
                    </div>

                    <Button type="submit" :disabled="form.processing || !form.query.trim()">
                        <Loader2 v-if="form.processing" class="h-4 w-4 mr-2 animate-spin" />
                        <Search v-else class="h-4 w-4 mr-2" />
                        Search
                    </Button>
                </div>
            </form>

            <!-- Error Alert -->
            <Alert v-if="error" variant="destructive" class="mb-6">
                <AlertCircle class="h-4 w-4" />
                <AlertDescription>{{ error }}</AlertDescription>
            </Alert>

            <!-- Results -->
            <div v-if="results !== null">
                <!-- Results Metadata -->
                <div class="flex items-center gap-4 mb-4 text-sm text-muted-foreground">
                    <span class="font-medium text-foreground">
                        {{ results.length }} result{{ results.length !== 1 ? 's' : '' }}
                    </span>
                    <span v-if="searchTime !== null" class="flex items-center gap-1">
                        <Clock class="h-3.5 w-3.5" />
                        {{ searchTime.toFixed(2) }}s
                    </span>
                    <Badge v-if="fromCache" variant="secondary" class="flex items-center gap-1">
                        <Database class="h-3 w-3" />
                        Cached
                    </Badge>
                </div>

                <!-- No Results -->
                <div v-if="results.length === 0" class="text-center py-12">
                    <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                        <Search class="h-8 w-8 text-muted-foreground" />
                    </div>
                    <h3 class="text-lg font-medium mb-2">No results found</h3>
                    <p class="text-muted-foreground">
                        Try a different search query or adjust your filters.
                    </p>
                </div>

                <!-- Result Cards -->
                <div v-else class="space-y-4">
                    <div
                        v-for="(result, index) in results"
                        :key="index"
                        class="bg-card border rounded-lg p-4 hover:border-primary/50 transition-colors"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="flex-1 min-w-0">
                                <a
                                    :href="result.url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="text-lg font-medium text-primary hover:underline flex items-center gap-2"
                                >
                                    {{ result.title }}
                                    <ExternalLink class="h-4 w-4 flex-shrink-0" />
                                </a>
                                <p class="text-xs text-muted-foreground mt-1 truncate">
                                    {{ truncateUrl(result.url) }}
                                </p>
                                <p v-if="result.snippet" class="text-sm text-foreground/80 mt-2 line-clamp-2">
                                    {{ result.snippet }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 mt-3 pt-3 border-t">
                            <Badge
                                v-if="result.engine"
                                variant="outline"
                                :class="['text-xs font-normal', getEngineBadgeClass(result.engine)]"
                            >
                                {{ result.engine }}
                            </Badge>
                            <span v-if="result.score !== null" class="text-xs text-muted-foreground">
                                Score: {{ result.score.toFixed(2) }}
                            </span>
                            <span v-if="result.published_date" class="text-xs text-muted-foreground">
                                {{ result.published_date }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty State (before search) -->
            <div v-else-if="!error" class="text-center py-12">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <Search class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">Ready to search</h3>
                <p class="text-muted-foreground">
                    Enter a query above to test the search functionality.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
