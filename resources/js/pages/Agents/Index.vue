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
import { Plus, Search, MoreHorizontal, Eye, Pencil, Trash2, Play } from 'lucide-vue-next';
import { formatDistanceToNow } from 'date-fns';
import type { Agent, PaginatedResponse } from '@/sdk/types';
import type { Auth } from '@/types/auth';
import { useDebounceFn } from '@vueuse/core';
import { listAgents, deleteAgent as deleteAgentApi } from '@/sdk/agents';

interface Props {
    auth: Auth;
}

defineProps<Props>();

// State
const loading = ref(true);
const error = ref<string | null>(null);
const agents = ref<(Agent & { tools_count?: number; executions_count?: number })[]>([]);
const meta = ref({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});

// Filters
const search = ref('');
const status = ref('all');
const currentPage = ref(1);

// Computed
const hasAgents = computed(() => agents.value.length > 0);

const getStatusVariant = (agentStatus: string) => {
    switch (agentStatus) {
        case 'active':
            return 'default';
        case 'inactive':
            return 'secondary';
        case 'error':
            return 'destructive';
        default:
            return 'outline';
    }
};

const formatDate = (date: string) => {
    return formatDistanceToNow(new Date(date), { addSuffix: true });
};

// Fetch agents from API
const fetchAgents = async () => {
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

        if (status.value && status.value !== 'all') {
            params.status = status.value;
        }

        const response = await listAgents(params);
        console.log('Fetched agents:', response);
        agents.value = response.data;
        meta.value = response.meta;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load agents';
        console.error('Failed to fetch agents:', e);
    } finally {
        loading.value = false;
    }
};

// Debounced filter application
const applyFilters = useDebounceFn(() => {
    currentPage.value = 1;
    fetchAgents();
}, 300);

// Watch for filter changes
watch([search, status], () => {
    applyFilters();
});

// Delete agent
const handleDeleteAgent = async (agent: Agent) => {
    if (!confirm(`Are you sure you want to delete "${agent.name}"?`)) {
        return;
    }

    try {
        await deleteAgentApi(agent.id);
        await fetchAgents();
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to delete agent');
    }
};

// Pagination
const goToPage = (page: number) => {
    currentPage.value = page;
    fetchAgents();
};

// Initial load
onMounted(() => {
    fetchAgents();
});
</script>

<template>
    <AuthenticatedLayout title="Agents" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Agents</h1>
                    <p class="text-muted-foreground">Manage your AI agents</p>
                </div>
                <Button as-child>
                    <Link href="/agents/create">
                        <Plus class="mr-2 h-4 w-4" />
                        New Agent
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
                                placeholder="Search agents..."
                                class="pl-10"
                            />
                        </div>
                        <Select v-model="status">
                            <SelectTrigger class="w-[180px]">
                                <SelectValue placeholder="Filter by status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="active">Active</SelectItem>
                                <SelectItem value="inactive">Inactive</SelectItem>
                                <SelectItem value="error">Error</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <!-- Agents Table -->
            <Card>
                <CardHeader>
                    <CardTitle>Your Agents</CardTitle>
                    <CardDescription v-if="!loading">
                        {{ meta.total }} agent{{ meta.total !== 1 ? 's' : '' }} found
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
                        <Button variant="link" @click="fetchAgents">Try again</Button>
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="!hasAgents" class="text-center py-8 text-muted-foreground">
                        No agents found. Create your first agent to get started.
                    </div>

                    <!-- Agents Table -->
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Backend</TableHead>
                                <TableHead>Tools</TableHead>
                                <TableHead>Executions</TableHead>
                                <TableHead>Created</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="agent in agents" :key="agent.id">
                                <TableCell class="font-medium">
                                    <Link
                                        :href="`/agents/${agent.id}`"
                                        class="hover:underline"
                                    >
                                        {{ agent.name }}
                                    </Link>
                                </TableCell>
                                <TableCell class="max-w-[200px] truncate">
                                    {{ agent.description || 'No description' }}
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="getStatusVariant(agent.status)">
                                        {{ agent.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>
                                    <Badge variant="outline">{{ agent.ai_backend }}</Badge>
                                </TableCell>
                                <TableCell>{{ agent.tools_count ?? 0 }}</TableCell>
                                <TableCell>{{ agent.executions_count ?? 0 }}</TableCell>
                                <TableCell>{{ formatDate(agent.created_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="sm">
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/agents/${agent.id}`">
                                                    <Eye class="mr-2 h-4 w-4" />
                                                    View
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/agents/${agent.id}/edit`">
                                                    <Pencil class="mr-2 h-4 w-4" />
                                                    Edit
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/agents/${agent.id}`">
                                                    <Play class="mr-2 h-4 w-4" />
                                                    Execute
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive"
                                                @click="handleDeleteAgent(agent)"
                                            >
                                                <Trash2 class="mr-2 h-4 w-4" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
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
