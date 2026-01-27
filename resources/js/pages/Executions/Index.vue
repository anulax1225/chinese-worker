<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
import { Eye, PlayCircle, RefreshCw, XCircle } from 'lucide-vue-next';
import { formatDistanceToNow } from 'date-fns';
import type { Execution } from '@/sdk/types';
import type { Auth } from '@/types/auth';
import { useDebounceFn } from '@vueuse/core';
import { listExecutions, cancelExecution } from '@/sdk/executions';

interface Props {
    auth: Auth;
}

defineProps<Props>();

// State
const loading = ref(true);
const error = ref<string | null>(null);
const executions = ref<Execution[]>([]);
const meta = ref({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
});

// Filters
const status = ref('all');
const currentPage = ref(1);

// Computed
const hasExecutions = computed(() => executions.value.length > 0);

const getStatusVariant = (executionStatus: string) => {
    switch (executionStatus) {
        case 'completed':
            return 'default';
        case 'running':
            return 'secondary';
        case 'failed':
            return 'destructive';
        case 'cancelled':
            return 'destructive';
        case 'pending':
            return 'outline';
        default:
            return 'outline';
    }
};

const formatDate = (date: string | null) => {
    if (!date) return 'N/A';
    return formatDistanceToNow(new Date(date), { addSuffix: true });
};

const getDuration = (execution: Execution) => {
    if (!execution.started_at) return 'N/A';
    if (!execution.completed_at) {
        if (execution.status === 'running') return 'Running...';
        return 'N/A';
    }
    const start = new Date(execution.started_at).getTime();
    const end = new Date(execution.completed_at).getTime();
    const seconds = Math.round((end - start) / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}m ${remainingSeconds}s`;
};

// Fetch executions from API
const fetchExecutions = async () => {
    loading.value = true;
    error.value = null;

    try {
        const params: Record<string, unknown> = {
            page: currentPage.value,
            per_page: 15,
        };

        if (status.value && status.value !== 'all') {
            params.status = status.value;
        }

        const response = await listExecutions(params);
        executions.value = response.data;
        meta.value = response.meta;
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load executions';
        console.error('Failed to fetch executions:', e);
    } finally {
        loading.value = false;
    }
};

// Debounced filter application
const applyFilters = useDebounceFn(() => {
    currentPage.value = 1;
    fetchExecutions();
}, 300);

// Watch for filter changes
watch(status, () => {
    applyFilters();
});

// Cancel execution
const handleCancelExecution = async (execution: Execution) => {
    if (!confirm(`Are you sure you want to cancel execution #${execution.id}?`)) {
        return;
    }

    try {
        await cancelExecution(execution.id);
        await fetchExecutions();
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to cancel execution');
    }
};

// Pagination
const goToPage = (page: number) => {
    currentPage.value = page;
    fetchExecutions();
};

// Refresh
const refresh = () => {
    fetchExecutions();
};

// Initial load
onMounted(() => {
    fetchExecutions();
});
</script>

<template>
    <AuthenticatedLayout title="Executions" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Executions</h1>
                    <p class="text-muted-foreground">View and monitor agent executions</p>
                </div>
                <Button variant="outline" @click="refresh">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    Refresh
                </Button>
            </div>

            <!-- Filters -->
            <Card>
                <CardHeader>
                    <CardTitle>Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex gap-4">
                        <Select v-model="status">
                            <SelectTrigger class="w-[200px]">
                                <SelectValue placeholder="Filter by status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="pending">Pending</SelectItem>
                                <SelectItem value="running">Running</SelectItem>
                                <SelectItem value="completed">Completed</SelectItem>
                                <SelectItem value="failed">Failed</SelectItem>
                                <SelectItem value="cancelled">Cancelled</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <!-- Executions Table -->
            <Card>
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <PlayCircle class="h-5 w-5" />
                        Executions
                    </CardTitle>
                    <CardDescription v-if="!loading">
                        {{ meta.total }} execution{{ meta.total !== 1 ? 's' : '' }} found
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
                        <Button variant="link" @click="fetchExecutions">Try again</Button>
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="!hasExecutions" class="text-center py-8 text-muted-foreground">
                        No executions found. Execute an agent to see results here.
                    </div>

                    <!-- Executions Table -->
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Agent</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Started</TableHead>
                                <TableHead>Duration</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="execution in executions" :key="execution.id">
                                <TableCell class="font-medium">#{{ execution.id }}</TableCell>
                                <TableCell>
                                    <Link
                                        v-if="execution.task?.agent"
                                        :href="`/agents/${execution.task.agent.id}`"
                                        class="hover:underline"
                                    >
                                        {{ execution.task.agent.name }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">Unknown</span>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="getStatusVariant(execution.status)">
                                        {{ execution.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatDate(execution.started_at) }}</TableCell>
                                <TableCell>{{ getDuration(execution) }}</TableCell>
                                <TableCell class="text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <Button as-child variant="ghost" size="sm">
                                            <Link :href="`/executions/${execution.id}`">
                                                <Eye class="mr-2 h-4 w-4" />
                                                View
                                            </Link>
                                        </Button>
                                        <Button
                                            v-if="execution.status === 'pending' || execution.status === 'running'"
                                            variant="ghost"
                                            size="sm"
                                            class="text-destructive"
                                            @click="handleCancelExecution(execution)"
                                        >
                                            <XCircle class="mr-2 h-4 w-4" />
                                            Cancel
                                        </Button>
                                    </div>
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
