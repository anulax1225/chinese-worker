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
import type { Tool, PaginatedResponse } from '@/types';

interface Filters {
    type: string | null;
    search: string | null;
}

const props = defineProps<{
    tools: PaginatedResponse<Tool & { agents_count: number }>;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const type = ref(props.filters.type || 'all');

const applyFilters = () => {
    router.get('/tools', {
        search: search.value || undefined,
        type: type.value === 'all' ? undefined : type.value,
    }, {
        preserveState: true,
        replace: true,
    });
};

const deleteTool = (tool: Tool) => {
    if (confirm(`Are you sure you want to delete "${tool.name}"?`)) {
        router.delete(`/tools/${tool.id}`);
    }
};

const getTypeColor = (type: string) => {
    const colors: Record<string, string> = {
        api: 'bg-blue-500',
        function: 'bg-purple-500',
        command: 'bg-orange-500',
        builtin: 'bg-green-500',
    };
    return colors[type] || 'bg-gray-500';
};
</script>

<template>
    <AppLayout title="Tools">
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Tools</h1>
                    <p class="text-muted-foreground">Manage your custom tools</p>
                </div>
                <Button as-child>
                    <Link href="/tools/create">
                        <Plus class="h-4 w-4 mr-2" />
                        Create Tool
                    </Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>All Tools</CardTitle>
                            <CardDescription>{{ tools.total }} tools total</CardDescription>
                        </div>
                        <div class="flex gap-2">
                            <div class="relative">
                                <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    v-model="search"
                                    placeholder="Search tools..."
                                    class="pl-8 w-[200px]"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                            <Select v-model="type" @update:model-value="applyFilters">
                                <SelectTrigger class="w-[130px]">
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
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Agents Using</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="tools.data.length === 0">
                                <TableCell colspan="4" class="text-center text-muted-foreground py-8">
                                    No tools found.
                                    <Link href="/tools/create" class="text-primary hover:underline ml-1">
                                        Create your first tool
                                    </Link>
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="tool in tools.data" :key="tool.id">
                                <TableCell class="font-medium">{{ tool.name }}</TableCell>
                                <TableCell>
                                    <Badge :class="getTypeColor(tool.type)" variant="secondary">
                                        {{ tool.type }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ tool.agents_count }}</TableCell>
                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon">
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
                                                @click="deleteTool(tool)"
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

                    <div v-if="tools.last_page > 1" class="flex items-center justify-between mt-4">
                        <p class="text-sm text-muted-foreground">
                            Showing {{ tools.from }} to {{ tools.to }} of {{ tools.total }} results
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="tools.current_page === 1"
                                @click="router.get('/tools', { page: tools.current_page - 1 })"
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="tools.current_page === tools.last_page"
                                @click="router.get('/tools', { page: tools.current_page + 1 })"
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
