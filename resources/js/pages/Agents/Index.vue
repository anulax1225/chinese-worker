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
    Bot,
    Filter,
    X,
    Loader2,
    Wrench,
} from 'lucide-vue-next';
import { destroy } from '@/actions/App/Http/Controllers/Api/V1/AgentController';
import type { Agent } from '@/types';

interface Filters {
    status: string | null;
    search: string | null;
}

const props = defineProps<{
    agents: (Agent & { tools_count: number })[];
    nextCursor: string | null;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const showFilters = ref(false);

const hasActiveFilters = computed(() => {
    return props.filters.search || props.filters.status;
});

const loadMoreParams = computed(() => {
    const params: Record<string, string> = {};
    if (props.nextCursor) {
        params.cursor = props.nextCursor;
    }
    if (props.filters.search) {
        params.search = props.filters.search;
    }
    if (props.filters.status) {
        params.status = props.filters.status;
    }
    return params;
});

const applyFilters = () => {
    router.get('/agents', {
        search: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
    }, {
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    search.value = '';
    status.value = 'all';
    router.get('/agents', {}, {
        preserveState: false,
        replace: true,
    });
};

const deleting = ref<number | null>(null);

const deleteAgent = async (agent: Agent, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (!confirm(`Are you sure you want to delete "${agent.name}"?`)) {
        return;
    }

    deleting.value = agent.id;
    try {
        const response = await fetch(destroy.url(agent.id), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });

        if (response.ok) {
            router.reload({ only: ['agents', 'nextCursor'] });
        }
    } finally {
        deleting.value = null;
    }
};

const getStatusDot = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-500',
        inactive: 'bg-gray-400',
        error: 'bg-red-500',
    };
    return colors[status] || 'bg-gray-400';
};

const getStatusBadgeClass = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-500/10 text-green-600 border-green-500/20',
        inactive: 'bg-gray-500/10 text-gray-600 border-gray-500/20',
        error: 'bg-red-500/10 text-red-600 border-red-500/20',
    };
    return colors[status] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};

watch(() => props.filters, (newFilters) => {
    search.value = newFilters.search || '';
    status.value = newFilters.status || 'all';
}, { immediate: true });
</script>

<template>
    <AppLayout title="Agents">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">Agents</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ agents.length }} loaded
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
                        <Link href="/agents/create">
                            <Plus class="h-4 w-4 mr-2" />
                            New Agent
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
                            placeholder="Search agents..."
                            class="pl-8"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <Select v-model="status" @update:model-value="applyFilters">
                        <SelectTrigger class="w-35">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="inactive">Inactive</SelectItem>
                            <SelectItem value="error">Error</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="agents.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <Bot class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No agents yet</h3>
                <p class="text-muted-foreground mb-6">
                    Create your first AI agent to get started.
                </p>
                <Button as-child>
                    <Link href="/agents/create">
                        <Plus class="h-4 w-4 mr-2" />
                        Create Agent
                    </Link>
                </Button>
            </div>

            <!-- Agent Cards -->
            <div v-else class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="agent in agents"
                        :key="agent.id"
                        :href="`/agents/${agent.id}`"
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
                                    <Link :href="`/agents/${agent.id}`" class="cursor-pointer">
                                        <Eye class="mr-2 h-4 w-4" />
                                        View
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem as-child>
                                    <Link :href="`/agents/${agent.id}/edit`" class="cursor-pointer">
                                        <Pencil class="mr-2 h-4 w-4" />
                                        Edit
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @click="deleteAgent(agent, $event)"
                                    class="cursor-pointer text-destructive"
                                    :disabled="deleting === agent.id"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    {{ deleting === agent.id ? 'Deleting...' : 'Delete' }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <div class="pr-8">
                            <!-- Header with status dot -->
                            <div class="flex items-center gap-2 mb-2">
                                <div :class="['h-2 w-2 rounded-full shrink-0', getStatusDot(agent.status)]" />
                                <h3 class="font-medium truncate">{{ agent.name }}</h3>
                            </div>

                            <!-- Description -->
                            <p
                                v-if="agent.description"
                                class="text-sm text-muted-foreground line-clamp-2 mb-3"
                            >
                                {{ agent.description }}
                            </p>
                            <p v-else class="text-sm text-muted-foreground/50 italic mb-3">
                                No description
                            </p>

                            <!-- Metadata -->
                            <div class="flex items-center gap-3 text-xs text-muted-foreground pt-3 border-t">
                                <Badge variant="outline" class="text-xs font-normal">
                                    {{ agent.ai_backend }}
                                </Badge>
                                <span class="flex items-center gap-1">
                                    <Wrench class="h-3 w-3" />
                                    {{ agent.tools_count }} tool{{ agent.tools_count !== 1 ? 's' : '' }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="nextCursor"
                    :data="['agents', 'nextCursor']"
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
                <div v-else-if="agents.length > 0" class="text-center py-6 text-sm text-muted-foreground">
                    You've reached the end
                </div>
            </div>
        </div>
    </AppLayout>
</template>
