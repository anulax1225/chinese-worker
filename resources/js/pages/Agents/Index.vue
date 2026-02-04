<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Plus, Search, MoreHorizontal, Eye, Pencil, Trash2 } from 'lucide-vue-next';
import type { Agent, PaginatedResponse } from '@/types';

interface Filters {
    status: string | null;
    search: string | null;
}

const props = defineProps<{
    agents: PaginatedResponse<Agent & { tools_count: number }>;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');

const applyFilters = () => {
    router.get('/agents', {
        search: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
    }, {
        preserveState: true,
        replace: true,
    });
};

const deleteAgent = (agent: Agent) => {
    if (confirm(`Are you sure you want to delete "${agent.name}"?`)) {
        router.delete(`/agents/${agent.id}`);
    }
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-500',
        inactive: 'bg-gray-500',
        error: 'bg-red-500',
    };
    return colors[status] || 'bg-gray-500';
};
</script>

<template>
    <AppLayout title="Agents">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Agents</h1>
                    <p class="text-sm text-muted-foreground">Manage your AI agents</p>
                </div>
                <Button as-child>
                    <Link href="/agents/create">
                        <Plus class="h-4 w-4 mr-2" />
                        Create Agent
                    </Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>All Agents</CardTitle>
                            <CardDescription>{{ agents.total }} agents total</CardDescription>
                        </div>
                        <div class="flex gap-2">
                            <div class="relative">
                                <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    v-model="search"
                                    placeholder="Search agents..."
                                    class="pl-8 w-[200px]"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                            <Select v-model="status" @update:model-value="applyFilters">
                                <SelectTrigger class="w-[130px]">
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
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="text-xs uppercase tracking-wide">Name</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Status</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Backend</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Tools</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="agents.data.length === 0">
                                <TableCell colspan="5" class="text-center text-muted-foreground py-8">
                                    No agents found.
                                    <Link href="/agents/create" class="text-primary hover:underline ml-1">
                                        Create your first agent
                                    </Link>
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="agent in agents.data" :key="agent.id">
                                <TableCell>
                                    <div>
                                        <p class="font-medium">{{ agent.name }}</p>
                                        <p v-if="agent.description" class="text-sm text-muted-foreground truncate max-w-[200px]">
                                            {{ agent.description }}
                                        </p>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :class="getStatusColor(agent.status)" variant="secondary">
                                        {{ agent.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ agent.ai_backend }}</TableCell>
                                <TableCell>{{ agent.tools_count }}</TableCell>
                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon">
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
                                                @click="deleteAgent(agent)"
                                                class="cursor-pointer text-destructive"
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
                    <div v-if="agents.last_page > 1" class="flex items-center justify-between mt-4">
                        <p class="text-sm text-muted-foreground">
                            Showing {{ agents.from }} to {{ agents.to }} of {{ agents.total }} results
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="agents.current_page === 1"
                                @click="router.get('/agents', { page: agents.current_page - 1 })"
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="agents.current_page === agents.last_page"
                                @click="router.get('/agents', { page: agents.current_page + 1 })"
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
