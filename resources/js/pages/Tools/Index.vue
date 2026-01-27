<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Plus, Search, MoreHorizontal, Eye, Pencil, Trash2, Terminal } from 'lucide-vue-next';
import { formatDistanceToNow } from 'date-fns';
import type { Tool } from '@/sdk/types';
import type { Auth } from '@/types/auth';
import { useDebounceFn } from '@vueuse/core';
import { listTools, deleteTool as deleteToolApi, type ToolsListResponse } from '@/sdk/tools';

interface Props {
    auth: Auth;
}

defineProps<Props>();

// State
const loading = ref(true);
const error = ref<string | null>(null);
const tools = ref<Tool[]>([]);
const meta = ref({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});

// Filters
const search = ref('');
const type = ref('all');
const currentPage = ref(1);

// Computed
const hasTools = computed(() => tools.value.length > 0);

const getTypeVariant = (toolType: string) => {
    switch (toolType) {
        case 'api':
            return 'default';
        case 'function':
            return 'secondary';
        case 'command':
            return 'outline';
        case 'builtin':
            return 'destructive';
        default:
            return 'outline';
    }
};

const formatDate = (date: string | null) => {
    if (!date) return 'System';
    return formatDistanceToNow(new Date(date), { addSuffix: true });
};

const isBuiltinTool = (tool: Tool) => {
    return tool.type === 'builtin';
};

// Fetch tools from API
const fetchTools = async () => {
    loading.value = true;
    error.value = null;

    try {
        const params: Record<string, unknown> = {
            page: currentPage.value,
            per_page: 15,
        };

        if (search.value) {
            params.search = search.value;
        }

        if (type.value && type.value !== 'all') {
            params.type = type.value;
        }

        const response: ToolsListResponse = await listTools(params);
        tools.value = response.data;
        meta.value = response.meta;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load tools';
        console.error('Failed to fetch tools:', e);
    } finally {
        loading.value = false;
    }
};

// Debounced filter application
const applyFilters = useDebounceFn(() => {
    currentPage.value = 1;
    fetchTools();
}, 300);

// Watch for filter changes
watch([search, type], () => {
    applyFilters();
});

// Delete tool
const handleDeleteTool = async (tool: Tool) => {
    if (isBuiltinTool(tool)) {
        alert('Builtin tools cannot be deleted');
        return;
    }

    if (!confirm(`Are you sure you want to delete "${tool.name}"?`)) {
        return;
    }

    try {
        await deleteToolApi(tool.id as number);
        await fetchTools();
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to delete tool');
    }
};

// Pagination
const goToPage = (page: number) => {
    currentPage.value = page;
    fetchTools();
};

// Initial load
onMounted(() => {
    fetchTools();
});
</script>

<template>
    <AuthenticatedLayout title="Tools" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Tools</h1>
                    <p class="text-muted-foreground">Manage your agent tools</p>
                </div>
                <Button as-child>
                    <Link href="/tools/create">
                        <Plus class="mr-2 h-4 w-4" />
                        New Tool
                    </Link>
                </Button>
            </div>

            <!-- Filters -->
            <Card>
                <CardHeader>
                    <CardTitle>Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex gap-4">
                        <div class="relative flex-1">
                            <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                v-model="search"
                                placeholder="Search tools..."
                                class="pl-10"
                            />
                        </div>
                        <Select v-model="type">
                            <SelectTrigger class="w-[180px]">
                                <SelectValue placeholder="Filter by type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Types</SelectItem>
                                <SelectItem value="api">API</SelectItem>
                                <SelectItem value="function">Function</SelectItem>
                                <SelectItem value="command">Command</SelectItem>
                                <SelectItem value="builtin">Builtin</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <!-- Tools Table -->
            <Card>
                <CardHeader>
                    <CardTitle>Your Tools</CardTitle>
                    <CardDescription v-if="!loading">
                        {{ meta.total }} tool{{ meta.total !== 1 ? 's' : '' }} found
                    </CardDescription>
                    <CardDescription v-else>
                        Loading...
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <!-- Loading State -->
                    <div v-if="loading" class="space-y-4">
                        <div v-for="i in 5" :key="i" class="flex items-center space-x-4">
                            <Skeleton class="h-12 w-full" />
                        </div>
                    </div>

                    <!-- Error State -->
                    <div v-else-if="error" class="text-center py-8 text-destructive">
                        {{ error }}
                        <Button variant="link" @click="fetchTools">Try again</Button>
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="!hasTools" class="text-center py-8 text-muted-foreground">
                        No tools found. Create your first tool to get started.
                    </div>

                    <!-- Tools Table -->
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="tool in tools" :key="tool.id">
                                <TableCell class="font-medium">
                                    <div class="flex items-center gap-2">
                                        <Terminal v-if="isBuiltinTool(tool)" class="h-4 w-4 text-muted-foreground" />
                                        <Link
                                            v-if="!isBuiltinTool(tool)"
                                            :href="`/tools/${tool.id}`"
                                            class="hover:underline"
                                        >
                                            {{ tool.name }}
                                        </Link>
                                        <span v-else>{{ tool.name }}</span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="getTypeVariant(tool.type)">
                                        {{ tool.type }}
                                    </Badge>
                                </TableCell>
                                <TableCell class="max-w-xs truncate">
                                    {{ tool.description || (tool.config && 'config' in tool.config ? '-' : '-') }}
                                </TableCell>
                                <TableCell>{{ formatDate(tool.created_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <DropdownMenu v-if="!isBuiltinTool(tool)">
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="sm">
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/tools/${tool.id}`">
                                                    <Eye class="mr-2 h-4 w-4" />
                                                    View
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/tools/${tool.id}/edit`">
                                                    <Pencil class="mr-2 h-4 w-4" />
                                                    Edit
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive"
                                                @click="handleDeleteTool(tool)"
                                            >
                                                <Trash2 class="mr-2 h-4 w-4" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                    <span v-else class="text-xs text-muted-foreground">System</span>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <!-- Pagination -->
                    <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">
                            Page {{ meta.current_page }} of {{ meta.last_page }} ({{ meta.total }} total)
                        </p>
                        <div class="flex gap-2">
                            <Button
                                v-for="page in meta.last_page"
                                :key="page"
                                :variant="page === meta.current_page ? 'default' : 'outline'"
                                size="sm"
                                @click="goToPage(page)"
                            >
                                {{ page }}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
