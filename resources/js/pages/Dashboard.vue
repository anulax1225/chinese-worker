<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Bot, Wrench, MessageSquare, TrendingUp, Plus } from 'lucide-vue-next';
import type { Conversation } from '@/types';

interface Stats {
    totalAgents: number;
    activeAgents: number;
    totalTools: number;
    totalConversations: number;
    successRate: number;
}

defineProps<{
    stats: Stats;
    recentConversations: Conversation[];
}>();

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
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
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">Dashboard</h1>
                    <p class="text-sm text-muted-foreground">Overview of your AI agents and conversations</p>
                </div>
                <Button as-child>
                    <Link href="/conversations/create">
                        <Plus class="h-4 w-4 mr-2" />
                        New Conversation
                    </Link>
                </Button>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Total Agents</CardTitle>
                        <Bot class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.totalAgents }}</div>
                        <p class="text-xs text-muted-foreground">
                            {{ stats.activeAgents }} active
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Total Tools</CardTitle>
                        <Wrench class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.totalTools }}</div>
                        <p class="text-xs text-muted-foreground">Custom tools</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Conversations</CardTitle>
                        <MessageSquare class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.totalConversations }}</div>
                        <p class="text-xs text-muted-foreground">All time</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Success Rate</CardTitle>
                        <TrendingUp class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.successRate }}%</div>
                        <p class="text-xs text-muted-foreground">Completed conversations</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent Conversations -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Recent Conversations</CardTitle>
                            <CardDescription>Your latest agent conversations</CardDescription>
                        </div>
                        <Button variant="outline" size="sm" as-child>
                            <Link href="/conversations">View All</Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="text-xs uppercase tracking-wide">Agent</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Status</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Turns</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Last Activity</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="recentConversations.length === 0">
                                <TableCell colspan="5" class="text-center text-muted-foreground py-8">
                                    No conversations yet.
                                    <Link href="/conversations/create" class="text-primary hover:underline ml-1">
                                        Start your first conversation
                                    </Link>
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="conversation in recentConversations" :key="conversation.id">
                                <TableCell class="font-medium">
                                    {{ conversation.agent?.name || 'Unknown Agent' }}
                                </TableCell>
                                <TableCell>
                                    <Badge :class="getStatusColor(conversation.status)" variant="secondary">
                                        {{ conversation.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ conversation.turn_count }}</TableCell>
                                <TableCell>{{ formatDate(conversation.last_activity_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <Button variant="ghost" size="sm" as-child>
                                        <Link :href="`/conversations/${conversation.id}`">View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
