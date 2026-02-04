<script setup lang="ts">
import { Link, router, WhenVisible } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Plus,
    Search,
    MoreHorizontal,
    Eye,
    Pencil,
    Trash2,
    Wrench,
    Filter,
    X,
    Loader2,
    Bot,
} from 'lucide-vue-next';
import type { Tool } from '@/types';

interface Filters {
    type: string | null;
    search: string | null;
}

const props = defineProps<{
    tools: (Tool & { agents_count: number })[];
    nextCursor: string | null;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const type = ref(props.filters.type || 'all');
const showFilters = ref(false);

const hasActiveFilters = computed(() => {
    return props.filters.search || props.filters.type;
});

const loadMoreParams = computed(() => {
    const params: Record<string, string> = {};
    if (props.nextCursor) {
        params.cursor = props.nextCursor;
    }
    if (props.filters.search) {
        params.search = props.filters.search;
    }
    if (props.filters.type) {
        params.type = props.filters.type;
    }
    return params;
});

const applyFilters = () => {
    router.get('/tools', {
        search: search.value || undefined,
        type: type.value === 'all' ? undefined : type.value,
    }, {
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    search.value = '';
    type.value = 'all';
    router.get('/tools', {}, {
        preserveState: false,
        replace: true,
    });
};

const deleteTool = (tool: Tool, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (confirm(`Are you sure you want to delete "${tool.name}"?`)) {
        router.delete(`/tools/${tool.id}`, {
            preserveState: false,
        });
    }
};

const getTypeBadgeClass = (type: string) => {
    const colors: Record<string, string> = {
        api: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        function: 'bg-purple-500/10 text-purple-600 border-purple-500/20',
        command: 'bg-orange-500/10 text-orange-600 border-orange-500/20',
        builtin: 'bg-green-500/10 text-green-600 border-green-500/20',
    };
    return colors[type] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};

watch(() => props.filters, (newFilters) => {
    search.value = newFilters.search || '';
    type.value = newFilters.type || 'all';
}, { immediate: true });
</script>

<template>
    <AppLayout title="Tools">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">Tools</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ tools.length }} loaded
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        :class="{ 'bg-accent': showFilters || hasActiveFilters }"
                        @click="showFilters = !showFilters"
                    >
                        <Filter class="h-4 w-4" />
                    </Button>
                    <Button as-child>
                        <Link href="/tools/create">
                            <Plus class="h-4 w-4 mr-2" />
                            New Tool
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Filters (collapsible) -->
            <div
                v-if="showFilters"
                class="mb-6 p-4 bg-muted/50 rounded-lg border"
            >
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium">Filters</span>
                    <Button
                        v-if="hasActiveFilters"
                        variant="ghost"
                        size="sm"
                        class="h-7 text-xs"
                        @click="clearFilters"
                    >
                        <X class="h-3 w-3 mr-1" />
                        Clear all
                    </Button>
                </div>
                <div class="flex gap-3 flex-wrap">
                    <div class="relative flex-1 min-w-50">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            v-model="search"
                            placeholder="Search tools..."
                            class="pl-8"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <Select v-model="type" @update:model-value="applyFilters">
                        <SelectTrigger class="w-35">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            <SelectItem value="api">API</SelectItem>
                            <SelectItem value="function">Function</SelectItem>
                            <SelectItem value="command">Command</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="tools.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <Wrench class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No tools yet</h3>
                <p class="text-muted-foreground mb-6">
                    Create custom tools for your agents to use.
                </p>
                <Button as-child>
                    <Link href="/tools/create">
                        <Plus class="h-4 w-4 mr-2" />
                        Create Tool
                    </Link>
                </Button>
            </div>

            <!-- Tool Cards -->
            <div v-else class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="tool in tools"
                        :key="tool.id"
                        :href="`/tools/${tool.id}`"
                        class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                    >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="absolute top-3 right-3 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                    @click.prevent
                                >
                                    <MoreHorizontal class="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem as-child>
                                    <Link :href="`/tools/${tool.id}`" class="cursor-pointer">
                                        <Eye class="mr-2 h-4 w-4" />
                                        View
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem as-child>
                                    <Link :href="`/tools/${tool.id}/edit`" class="cursor-pointer">
                                        <Pencil class="mr-2 h-4 w-4" />
                                        Edit
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @click="deleteTool(tool, $event)"
                                    class="cursor-pointer text-destructive"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <div class="pr-8">
                            <!-- Header with icon -->
                            <div class="flex items-center gap-3 mb-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/10">
                                    <Wrench class="h-4 w-4 text-primary" />
                                </div>
                                <h3 class="font-medium truncate">{{ tool.name }}</h3>
                            </div>

                            <!-- Metadata -->
                            <div class="flex items-center gap-3 text-xs text-muted-foreground pt-3 border-t">
                                <Badge variant="outline" :class="['text-xs font-normal', getTypeBadgeClass(tool.type)]">
                                    {{ tool.type }}
                                </Badge>
                                <span class="flex items-center gap-1">
                                    <Bot class="h-3 w-3" />
                                    {{ tool.agents_count }} agent{{ tool.agents_count !== 1 ? 's' : '' }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="nextCursor"
                    :data="['tools', 'nextCursor']"
                    :params="loadMoreParams"
                    :options="{ rootMargin: '200px 0px' }"
                >
                    <div class="flex justify-center py-6">
                        <div class="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 class="h-4 w-4 animate-spin" />
                            Loading more...
                        </div>
                    </div>
                </WhenVisible>

                <!-- End of list indicator -->
                <div v-else-if="tools.length > 0" class="text-center py-6 text-sm text-muted-foreground">
                    You've reached the end
                </div>
            </div>
        </div>
    </AppLayout>
</template>
