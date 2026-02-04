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
import { Plus, Search, MoreHorizontal, Eye, Trash2 } from 'lucide-vue-next';
import type { Conversation, Agent, PaginatedResponse } from '@/types';

interface Filters {
    status: string | null;
    agent_id: string | null;
    search: string | null;
}

const props = defineProps<{
    conversations: PaginatedResponse<Conversation>;
    agents: Pick<Agent, 'id' | 'name'>[];
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const agentId = ref(props.filters.agent_id || 'all');

const applyFilters = () => {
    router.get('/conversations', {
        search: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
        agent_id: agentId.value === 'all' ? undefined : agentId.value,
    }, {
        preserveState: true,
        replace: true,
    });
};

const deleteConversation = (conversation: Conversation) => {
    if (confirm('Are you sure you want to delete this conversation?')) {
        router.delete(`/conversations/${conversation.id}`);
    }
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-blue-500',
        completed: 'bg-green-500',
        failed: 'bg-red-500',
        cancelled: 'bg-gray-500',
    };
    return colors[status] || 'bg-gray-500';
};

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <AppLayout title="Conversations">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Conversations</h1>
                    <p class="text-sm text-muted-foreground">Your agent conversations</p>
                </div>
                <Button as-child>
                    <Link href="/conversations/create">
                        <Plus class="h-4 w-4 mr-2" />
                        New Conversation
                    </Link>
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>All Conversations</CardTitle>
                            <CardDescription>{{ conversations.total }} conversations total</CardDescription>
                        </div>
                        <div class="flex gap-2 flex-wrap">
                            <div class="relative">
                                <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    v-model="search"
                                    placeholder="Search..."
                                    class="pl-8 w-[150px]"
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
                                    <SelectItem value="completed">Completed</SelectItem>
                                    <SelectItem value="failed">Failed</SelectItem>
                                </SelectContent>
                            </Select>
                            <Select v-model="agentId" @update:model-value="applyFilters">
                                <SelectTrigger class="w-[150px]">
                                    <SelectValue placeholder="Agent" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Agents</SelectItem>
                                    <SelectItem v-for="agent in agents" :key="agent.id" :value="String(agent.id)">
                                        {{ agent.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="text-xs uppercase tracking-wide">Agent</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Status</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Turns</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Tokens</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Last Activity</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="conversations.data.length === 0">
                                <TableCell colspan="6" class="text-center text-muted-foreground py-8">
                                    No conversations found.
                                    <Link href="/conversations/create" class="text-primary hover:underline ml-1">
                                        Start your first conversation
                                    </Link>
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="conversation in conversations.data" :key="conversation.id">
                                <TableCell class="font-medium">
                                    {{ conversation.agent?.name || 'Unknown' }}
                                </TableCell>
                                <TableCell>
                                    <Badge :class="getStatusColor(conversation.status)" variant="secondary">
                                        {{ conversation.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ conversation.turn_count }}</TableCell>
                                <TableCell>{{ conversation.total_tokens.toLocaleString() }}</TableCell>
                                <TableCell>{{ formatDate(conversation.last_activity_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="icon">
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem as-child>
                                                <Link :href="`/conversations/${conversation.id}`" class="cursor-pointer">
                                                    <Eye class="mr-2 h-4 w-4" />
                                                    View
                                                </Link>
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                @click="deleteConversation(conversation)"
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

                    <div v-if="conversations.last_page > 1" class="flex items-center justify-between mt-4">
                        <p class="text-sm text-muted-foreground">
                            Showing {{ conversations.from }} to {{ conversations.to }} of {{ conversations.total }} results
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="conversations.current_page === 1"
                                @click="router.get('/conversations', { page: conversations.current_page - 1 })"
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="conversations.current_page === conversations.last_page"
                                @click="router.get('/conversations', { page: conversations.current_page + 1 })"
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
