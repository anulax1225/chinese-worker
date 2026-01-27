<script setup lang="ts">
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    ArrowLeft,
    Pencil,
    Trash2,
    Play,
    Wrench,
    Clock,
    Bot,
    Loader2,
} from 'lucide-vue-next';
import { formatDistanceToNow, format } from 'date-fns';
import type { Agent, Execution } from '@/types/models';
import type { Auth } from '@/types/auth';
import { useAgentExecution } from '@/composables';

interface Props {
    auth: Auth;
    agent: Agent & { executions?: Execution[] };
}

const props = defineProps<Props>();

const executeDialogOpen = ref(false);
const executeInput = ref('');

// Use the composable for agent execution
const { execute, isExecuting, error: executeError, execution, reset } = useAgentExecution();

// Watch for successful execution to redirect
watch(execution, (newExecution) => {
    if (newExecution?.id) {
        executeDialogOpen.value = false;
        executeInput.value = '';
        router.visit(`/executions/${newExecution.id}`);
    }
});

const getStatusVariant = (status: string) => {
    switch (status) {
        case 'active':
        case 'completed':
            return 'default';
        case 'inactive':
        case 'running':
            return 'secondary';
        case 'error':
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

const formatFullDate = (date: string) => {
    return format(new Date(date), 'PPpp');
};

const handleExecuteAgent = async () => {
    await execute(props.agent.id, {
        payload: {
            input: executeInput.value,
        },
    });
};

const handleDialogClose = () => {
    reset();
    executeInput.value = '';
};

const deleteAgent = () => {
    if (confirm(`Are you sure you want to delete "${props.agent.name}"? This action cannot be undone.`)) {
        router.delete(`/agents/${props.agent.id}`);
    }
};
</script>

<template>
    <AuthenticatedLayout :title="agent.name" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Button as-child variant="ghost" size="sm">
                        <Link href="/agents">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-3xl font-bold tracking-tight">{{ agent.name }}</h1>
                            <Badge :variant="getStatusVariant(agent.status)">
                                {{ agent.status }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground">{{ agent.description || 'No description' }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Dialog v-model:open="executeDialogOpen" @update:open="(open: boolean) => !open && handleDialogClose()">
                        <DialogTrigger as-child>
                            <Button :disabled="agent.status !== 'active'">
                                <Play class="mr-2 h-4 w-4" />
                                Execute
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Execute Agent</DialogTitle>
                                <DialogDescription>
                                    Provide input for the agent to process
                                </DialogDescription>
                            </DialogHeader>
                            <div class="space-y-4 py-4">
                                <div class="space-y-2">
                                    <Label for="execute-input">Input</Label>
                                    <Textarea
                                        id="execute-input"
                                        v-model="executeInput"
                                        placeholder="Enter your prompt or question..."
                                        rows="5"
                                    />
                                </div>
                                <div v-if="executeError" class="rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive">
                                    {{ executeError }}
                                </div>
                            </div>
                            <DialogFooter>
                                <Button variant="outline" @click="handleDialogClose">
                                    Cancel
                                </Button>
                                <Button @click="handleExecuteAgent" :disabled="isExecuting || !executeInput.trim()">
                                    <Loader2 v-if="isExecuting" class="mr-2 h-4 w-4 animate-spin" />
                                    <Play v-else class="mr-2 h-4 w-4" />
                                    {{ isExecuting ? 'Executing...' : 'Execute' }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    <Button as-child variant="outline">
                        <Link :href="`/agents/${agent.id}/edit`">
                            <Pencil class="mr-2 h-4 w-4" />
                            Edit
                        </Link>
                    </Button>
                    <Button variant="destructive" @click="deleteAgent">
                        <Trash2 class="mr-2 h-4 w-4" />
                        Delete
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Instructions -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Instructions / System Prompt</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre class="whitespace-pre-wrap rounded-lg bg-muted p-4 text-sm font-mono">{{ agent.code }}</pre>
                        </CardContent>
                    </Card>

                    <!-- Recent Executions -->
                    <Card>
                        <CardHeader>
                            <div class="flex items-center justify-between">
                                <div>
                                    <CardTitle>Recent Executions</CardTitle>
                                    <CardDescription>Latest runs of this agent</CardDescription>
                                </div>
                                <Button as-child variant="outline" size="sm">
                                    <Link :href="`/executions?agent_id=${agent.id}`">View All</Link>
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!agent.executions || agent.executions.length === 0" class="text-center py-8 text-muted-foreground">
                                No executions yet. Click "Execute" to run this agent.
                            </div>
                            <Table v-else>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>ID</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Started</TableHead>
                                        <TableHead>Duration</TableHead>
                                        <TableHead class="text-right">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-for="execution in agent.executions" :key="execution.id">
                                        <TableCell class="font-medium">#{{ execution.id }}</TableCell>
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
                                                    : execution.status === 'running'
                                                      ? 'Running...'
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

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Details -->
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div class="flex items-center gap-3">
                                <Bot class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">AI Backend</p>
                                    <Badge variant="outline">{{ agent.ai_backend }}</Badge>
                                </div>
                            </div>
                            <Separator />
                            <div class="flex items-center gap-3">
                                <Clock class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">Created</p>
                                    <p class="text-sm font-medium">{{ formatFullDate(agent.created_at) }}</p>
                                </div>
                            </div>
                            <Separator />
                            <div class="flex items-center gap-3">
                                <Clock class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">Last Updated</p>
                                    <p class="text-sm font-medium">{{ formatFullDate(agent.updated_at) }}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Tools -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Wrench class="h-5 w-5" />
                                Attached Tools
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!agent.tools || agent.tools.length === 0" class="text-center py-4 text-muted-foreground">
                                No tools attached
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    v-for="tool in agent.tools"
                                    :key="tool.id"
                                    class="flex items-center justify-between rounded-lg border p-3"
                                >
                                    <div>
                                        <p class="font-medium">{{ tool.name }}</p>
                                        <Badge variant="outline" class="text-xs">{{ tool.type }}</Badge>
                                    </div>
                                    <Button as-child variant="ghost" size="sm">
                                        <Link :href="`/tools/${tool.id}`">View</Link>
                                    </Button>
                                </div>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Config -->
                    <Card v-if="agent.config && Object.keys(agent.config).length > 0">
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre class="whitespace-pre-wrap rounded-lg bg-muted p-3 text-xs font-mono">{{ JSON.stringify(agent.config, null, 2) }}</pre>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
