<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Bot, Wrench, PlayCircle, TrendingUp, Plus, FolderUp } from 'lucide-vue-next';
import { formatDistanceToNow } from 'date-fns';
import type { Execution } from '@/types/models';
import type { Auth } from '@/types/auth';

interface Props {
    auth: Auth;
    stats: {
        totalAgents: number;
        activeAgents: number;
        totalTools: number;
        totalExecutions: number;
        successRate: number;
    };
    recentExecutions: Execution[];
}

const props = defineProps<Props>();

const getStatusVariant = (status: string) => {
    switch (status) {
        case 'completed':
            return 'default';
        case 'running':
            return 'secondary';
        case 'failed':
            return 'destructive';
        default:
            return 'outline';
    }
};

const formatDate = (date: string | null) => {
    if (!date) return 'N/A';
    return formatDistanceToNow(new Date(date), { addSuffix: true });
};
</script>

<template>
    <AuthenticatedLayout title="Dashboard" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Dashboard</h1>
                    <p class="text-muted-foreground">Welcome back, {{ auth.user?.name }}</p>
                </div>
                <div class="flex gap-2">
                    <Button as-child>
                        <Link href="/agents/create">
                            <Plus class="mr-2 h-4 w-4" />
                            New Agent
                        </Link>
                    </Button>
                    <Button as-child variant="outline">
                        <Link href="/files">
                            <FolderUp class="mr-2 h-4 w-4" />
                            Upload File
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
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
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Total Tools</CardTitle>
                        <Wrench class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.totalTools }}</div>
                        <p class="text-xs text-muted-foreground">Available tools</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Total Executions</CardTitle>
                        <PlayCircle class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.totalExecutions }}</div>
                        <p class="text-xs text-muted-foreground">All time</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle class="text-sm font-medium">Success Rate</CardTitle>
                        <TrendingUp class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.successRate }}%</div>
                        <p class="text-xs text-muted-foreground">Completion rate</p>
                    </CardContent>
                </Card>
            </div>

            <!-- Recent Executions -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle>Recent Executions</CardTitle>
                            <CardDescription>Your latest agent executions</CardDescription>
                        </div>
                        <Button as-child variant="outline" size="sm">
                            <Link href="/executions">View All</Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="recentExecutions.length === 0" class="text-center py-8 text-muted-foreground">
                        No executions yet. Create an agent and run it to see results here.
                    </div>
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
                            <TableRow v-for="execution in recentExecutions" :key="execution.id">
                                <TableCell class="font-medium">#{{ execution.id }}</TableCell>
                                <TableCell>{{ execution.task?.agent?.name || 'N/A' }}</TableCell>
                                <TableCell>
                                    <Badge :variant="getStatusVariant(execution.status)">
                                        {{ execution.status }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatDate(execution.started_at) }}</TableCell>
                                <TableCell>
                                    {{
                                        execution.completed_at && execution.started_at
                                            ? Math.round(
                                                  (new Date(execution.completed_at).getTime() -
                                                      new Date(execution.started_at).getTime()) /
                                                      1000,
                                              ) + 's'
                                            : 'N/A'
                                    }}
                                </TableCell>
                                <TableCell class="text-right">
                                    <Button as-child variant="ghost" size="sm">
                                        <Link :href="`/executions/${execution.id}`">View</Link>
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
