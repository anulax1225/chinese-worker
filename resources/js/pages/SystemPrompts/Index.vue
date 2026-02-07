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
    FileText,
    Filter,
    X,
    Loader2,
} from 'lucide-vue-next';
import { destroy } from '@/actions/App/Http/Controllers/Api/V1/SystemPromptController';
import type { SystemPrompt } from '@/types';

interface Filters {
    search: string | null;
    active: string | null;
}

const props = defineProps<{
    prompts: SystemPrompt[];
    nextCursor: string | null;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const active = ref(props.filters.active || 'all');
const showFilters = ref(false);
const deleting = ref<number | null>(null);

const hasActiveFilters = computed(() => {
    return props.filters.search || props.filters.active;
});

const loadMoreParams = computed(() => {
    const params: Record<string, string> = {};
    if (props.nextCursor) {
        params.cursor = props.nextCursor;
    }
    if (props.filters.search) {
        params.search = props.filters.search;
    }
    if (props.filters.active) {
        params.active = props.filters.active;
    }
    return params;
});

const applyFilters = () => {
    router.get('/system-prompts', {
        search: search.value || undefined,
        active: active.value === 'all' ? undefined : active.value,
    }, {
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    search.value = '';
    active.value = 'all';
    router.get('/system-prompts', {}, {
        preserveState: false,
        replace: true,
    });
};

const deletePrompt = async (prompt: SystemPrompt, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (!confirm(`Are you sure you want to delete "${prompt.name}"?`)) {
        return;
    }

    deleting.value = prompt.id;
    try {
        const response = await fetch(destroy.url(prompt.id), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });

        if (response.ok) {
            router.reload({ only: ['prompts', 'nextCursor'] });
        }
    } finally {
        deleting.value = null;
    }
};

watch(() => props.filters, (newFilters) => {
    search.value = newFilters.search || '';
    active.value = newFilters.active || 'all';
}, { immediate: true });
</script>

<template>
    <AppLayout title="System Prompts">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">System Prompts</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ prompts.length }} loaded
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
                        <Link href="/system-prompts/create">
                            <Plus class="h-4 w-4 mr-2" />
                            New Prompt
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
                            placeholder="Search prompts..."
                            class="pl-8"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <Select v-model="active" @update:model-value="applyFilters">
                        <SelectTrigger class="w-35">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="1">Active</SelectItem>
                            <SelectItem value="0">Inactive</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="prompts.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <FileText class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No system prompts yet</h3>
                <p class="text-muted-foreground mb-6">
                    Create reusable system prompts for your agents.
                </p>
                <Button as-child>
                    <Link href="/system-prompts/create">
                        <Plus class="h-4 w-4 mr-2" />
                        Create Prompt
                    </Link>
                </Button>
            </div>

            <!-- Prompt Cards -->
            <div v-else class="space-y-6">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="prompt in prompts"
                        :key="prompt.id"
                        :href="`/system-prompts/${prompt.id}`"
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
                                    <Link :href="`/system-prompts/${prompt.id}`" class="cursor-pointer">
                                        <Eye class="mr-2 h-4 w-4" />
                                        View
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem as-child>
                                    <Link :href="`/system-prompts/${prompt.id}/edit`" class="cursor-pointer">
                                        <Pencil class="mr-2 h-4 w-4" />
                                        Edit
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @click="deletePrompt(prompt, $event)"
                                    class="cursor-pointer text-destructive"
                                    :disabled="deleting === prompt.id"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    {{ deleting === prompt.id ? 'Deleting...' : 'Delete' }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <div class="pr-8">
                            <!-- Header with icon -->
                            <div class="flex items-center gap-3 mb-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/10">
                                    <FileText class="h-4 w-4 text-primary" />
                                </div>
                                <h3 class="font-medium truncate">{{ prompt.name }}</h3>
                            </div>

                            <!-- Slug -->
                            <p class="text-xs text-muted-foreground mb-3 font-mono truncate">
                                {{ prompt.slug }}
                            </p>

                            <!-- Metadata -->
                            <div class="flex items-center gap-3 text-xs text-muted-foreground pt-3 border-t">
                                <Badge
                                    variant="outline"
                                    :class="[
                                        'text-xs font-normal',
                                        prompt.is_active
                                            ? 'bg-green-500/10 text-green-600 border-green-500/20'
                                            : 'bg-gray-500/10 text-gray-600 border-gray-500/20'
                                    ]"
                                >
                                    {{ prompt.is_active ? 'Active' : 'Inactive' }}
                                </Badge>
                                <span v-if="prompt.required_variables?.length">
                                    {{ prompt.required_variables.length }} var{{ prompt.required_variables.length !== 1 ? 's' : '' }}
                                </span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="nextCursor"
                    :data="['prompts', 'nextCursor']"
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
                <div v-else-if="prompts.length > 0" class="text-center py-6 text-sm text-muted-foreground">
                    You've reached the end
                </div>
            </div>
        </div>
    </AppLayout>
</template>
