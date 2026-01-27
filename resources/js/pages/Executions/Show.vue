<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ArrowLeft,
    Bot,
    Clock,
    FileIcon,
    Download,
    RefreshCw,
    CheckCircle,
    XCircle,
    Loader2,
    AlertCircle,
    Cpu,
    Hash,
    Ban,
} from 'lucide-vue-next';
import { format, formatDistanceToNow } from 'date-fns';
import type { Execution, File as FileModel } from '@/sdk/types';
import type { Auth } from '@/types/auth';
import { getExecution, cancelExecution } from '@/sdk/executions';

interface Props {
    auth: Auth;
    id: number;
}

const props = defineProps<Props>();

// State
const loading = ref(true);
const error = ref<string | null>(null);
const execution = ref<(Execution & { files?: FileModel[] }) | null>(null);

const getStatusVariant = (status: string) => {
    switch (status) {
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

const getStatusIcon = (status: string) => {
    switch (status) {
        case 'completed':
            return CheckCircle;
        case 'running':
            return Loader2;
        case 'failed':
            return XCircle;
        case 'cancelled':
            return Ban;
        case 'pending':
            return Clock;
        default:
            return AlertCircle;
    }
};

const formatFullDate = (date: string | null) => {
    if (!date) return 'N/A';
    return format(new Date(date), 'PPpp');
};

const duration = computed(() => {
    if (!execution.value?.started_at) return null;
    if (!execution.value?.completed_at) {
        if (execution.value?.status === 'running') return 'Running...';
        return null;
    }
    const start = new Date(execution.value.started_at).getTime();
    const end = new Date(execution.value.completed_at).getTime();
    const ms = end - start;
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return `${seconds} seconds`;
    const minutes = Math.floor(seconds / 60);
    const remainingSeconds = seconds % 60;
    return `${minutes}m ${remainingSeconds}s`;
});

const formatSize = (bytes: number) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return `${size.toFixed(1)} ${units[unitIndex]}`;
};

const getFileName = (path: string) => {
    return path.split('/').pop() || path;
};

// Fetch execution from API
const fetchExecution = async () => {
    loading.value = true;
    error.value = null;

    try {
        execution.value = await getExecution(props.id);
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load execution';
        console.error('Failed to fetch execution:', e);
    } finally {
        loading.value = false;
    }
};

// Cancel execution
const handleCancelExecution = async () => {
    if (!execution.value) return;

    if (!confirm(`Are you sure you want to cancel execution #${execution.value.id}?`)) {
        return;
    }

    try {
        const result = await cancelExecution(execution.value.id);
        execution.value = result.execution;
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to cancel execution');
    }
};

const refresh = () => {
    fetchExecution();
};

// Initial load
onMounted(() => {
    fetchExecution();
});
</script>

<template>
    <AuthenticatedLayout :title="execution ? `Execution #${execution.id}` : 'Execution'" :auth="auth">
        <div class="space-y-6">
            <!-- Loading State -->
            <div v-if="loading" class="space-y-6">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Skeleton class="h-9 w-20" />
                        <div>
                            <Skeleton class="h-9 w-48" />
                            <Skeleton class="h-4 w-32 mt-2" />
                        </div>
                    </div>
                    <Skeleton class="h-9 w-24" />
                </div>
                <Skeleton class="h-64 w-full" />
            </div>

            <!-- Error State -->
            <div v-else-if="error" class="text-center py-8">
                <p class="text-destructive mb-4">{{ error }}</p>
                <Button variant="link" @click="fetchExecution">Try again</Button>
            </div>

            <!-- Content -->
            <template v-else-if="execution">
                <!-- Page Header -->
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <Button as-child variant="ghost" size="sm">
                            <Link href="/executions">
                                <ArrowLeft class="mr-2 h-4 w-4" />
                                Back
                            </Link>
                        </Button>
                        <div>
                            <div class="flex items-center gap-3">
                                <h1 class="text-3xl font-bold tracking-tight">Execution #{{ execution.id }}</h1>
                                <Badge :variant="getStatusVariant(execution.status)" class="flex items-center gap-1">
                                    <component
                                        :is="getStatusIcon(execution.status)"
                                        class="h-3 w-3"
                                        :class="{ 'animate-spin': execution.status === 'running' }"
                                    />
                                    {{ execution.status }}
                                </Badge>
                            </div>
                            <p class="text-muted-foreground">
                                Agent: {{ execution.task?.agent?.name || 'Unknown' }}
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button variant="outline" @click="refresh">
                            <RefreshCw class="mr-2 h-4 w-4" />
                            Refresh
                        </Button>
                        <Button
                            v-if="execution.status === 'pending' || execution.status === 'running'"
                            variant="destructive"
                            @click="handleCancelExecution"
                        >
                            <XCircle class="mr-2 h-4 w-4" />
                            Cancel
                        </Button>
                        <Button v-if="execution.task?.agent" as-child>
                            <Link :href="`/agents/${execution.task.agent.id}`">
                                <Bot class="mr-2 h-4 w-4" />
                                View Agent
                            </Link>
                        </Button>
                    </div>
                </div>

                <!-- Progress for running -->
                <Card v-if="execution.status === 'running'">
                    <CardContent class="pt-6">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between text-sm">
                                <span class="flex items-center gap-2">
                                    <Loader2 class="h-4 w-4 animate-spin" />
                                    Execution in progress...
                                </span>
                            </div>
                            <Progress :model-value="50" class="animate-pulse" />
                        </div>
                    </CardContent>
                </Card>

                <div class="grid gap-6 lg:grid-cols-3">
                    <!-- Main Content -->
                    <div class="lg:col-span-2 space-y-6">
                        <!-- Tabs for Result/Logs/Error -->
                        <Card>
                            <CardHeader>
                                <CardTitle>Output</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Tabs default-value="result" class="w-full">
                                    <TabsList class="grid w-full grid-cols-3">
                                        <TabsTrigger value="result">Result</TabsTrigger>
                                        <TabsTrigger value="logs">Logs</TabsTrigger>
                                        <TabsTrigger value="error" :disabled="!execution.error">Error</TabsTrigger>
                                    </TabsList>
                                    <TabsContent value="result" class="mt-4">
                                        <div v-if="execution.result" class="space-y-4">
                                            <!-- AI Response Content -->
                                            <div v-if="execution.result.content">
                                                <p class="text-sm font-medium text-muted-foreground mb-2">Response</p>
                                                <div class="rounded-lg bg-muted p-4">
                                                    <pre class="whitespace-pre-wrap text-sm font-mono">{{ execution.result.content }}</pre>
                                                </div>
                                            </div>

                                            <!-- Metadata -->
                                            <div v-if="execution.result.model || execution.result.tokens_used" class="grid gap-4 md:grid-cols-3">
                                                <div v-if="execution.result.model" class="flex items-center gap-2 rounded-lg border p-3">
                                                    <Cpu class="h-4 w-4 text-muted-foreground" />
                                                    <div>
                                                        <p class="text-xs text-muted-foreground">Model</p>
                                                        <p class="text-sm font-medium">{{ execution.result.model }}</p>
                                                    </div>
                                                </div>
                                                <div v-if="execution.result.tokens_used" class="flex items-center gap-2 rounded-lg border p-3">
                                                    <Hash class="h-4 w-4 text-muted-foreground" />
                                                    <div>
                                                        <p class="text-xs text-muted-foreground">Tokens Used</p>
                                                        <p class="text-sm font-medium">{{ execution.result.tokens_used }}</p>
                                                    </div>
                                                </div>
                                                <div v-if="execution.result.finish_reason" class="flex items-center gap-2 rounded-lg border p-3">
                                                    <CheckCircle class="h-4 w-4 text-muted-foreground" />
                                                    <div>
                                                        <p class="text-xs text-muted-foreground">Finish Reason</p>
                                                        <p class="text-sm font-medium">{{ execution.result.finish_reason }}</p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div v-else class="text-center py-8 text-muted-foreground">
                                            <p v-if="execution.status === 'pending'">Execution not yet started</p>
                                            <p v-else-if="execution.status === 'running'">Execution in progress...</p>
                                            <p v-else-if="execution.status === 'cancelled'">Execution was cancelled</p>
                                            <p v-else>No result available</p>
                                        </div>
                                    </TabsContent>
                                    <TabsContent value="logs" class="mt-4">
                                        <div v-if="execution.logs" class="rounded-lg bg-muted p-4 max-h-96 overflow-auto">
                                            <pre class="whitespace-pre-wrap text-sm font-mono text-muted-foreground">{{ execution.logs }}</pre>
                                        </div>
                                        <div v-else class="text-center py-8 text-muted-foreground">
                                            No logs available
                                        </div>
                                    </TabsContent>
                                    <TabsContent value="error" class="mt-4">
                                        <div v-if="execution.error" class="rounded-lg bg-destructive/10 border border-destructive/20 p-4">
                                            <pre class="whitespace-pre-wrap text-sm font-mono text-destructive">{{ execution.error }}</pre>
                                        </div>
                                    </TabsContent>
                                </Tabs>
                            </CardContent>
                        </Card>

                        <!-- Files -->
                        <Card v-if="execution.files && execution.files.length > 0">
                            <CardHeader>
                                <CardTitle class="flex items-center gap-2">
                                    <FileIcon class="h-5 w-5" />
                                    Associated Files
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Name</TableHead>
                                            <TableHead>Role</TableHead>
                                            <TableHead>Size</TableHead>
                                            <TableHead class="text-right">Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="file in execution.files" :key="file.id">
                                            <TableCell class="font-medium">{{ getFileName(file.path) }}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline">{{ (file as any).pivot?.role || file.type }}</Badge>
                                            </TableCell>
                                            <TableCell>{{ formatSize(file.size) }}</TableCell>
                                            <TableCell class="text-right">
                                                <Button as-child variant="ghost" size="sm">
                                                    <a :href="`/files/${file.id}`" download>
                                                        <Download class="mr-2 h-4 w-4" />
                                                        Download
                                                    </a>
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
                                    <component
                                        :is="getStatusIcon(execution.status)"
                                        class="h-5 w-5"
                                        :class="{
                                            'text-green-500': execution.status === 'completed',
                                            'text-blue-500 animate-spin': execution.status === 'running',
                                            'text-red-500': execution.status === 'failed' || execution.status === 'cancelled',
                                            'text-muted-foreground': execution.status === 'pending',
                                        }"
                                    />
                                    <div>
                                        <p class="text-sm text-muted-foreground">Status</p>
                                        <Badge :variant="getStatusVariant(execution.status)">{{ execution.status }}</Badge>
                                    </div>
                                </div>
                                <Separator />
                                <div class="flex items-center gap-3">
                                    <Bot class="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <p class="text-sm text-muted-foreground">Agent</p>
                                        <Link
                                            v-if="execution.task?.agent"
                                            :href="`/agents/${execution.task.agent.id}`"
                                            class="text-sm font-medium hover:underline"
                                        >
                                            {{ execution.task.agent.name }}
                                        </Link>
                                        <p v-else class="text-sm font-medium">Unknown</p>
                                    </div>
                                </div>
                                <Separator />
                                <div class="flex items-center gap-3">
                                    <Clock class="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <p class="text-sm text-muted-foreground">Started</p>
                                        <p class="text-sm font-medium">{{ formatFullDate(execution.started_at) }}</p>
                                    </div>
                                </div>
                                <Separator />
                                <div class="flex items-center gap-3">
                                    <Clock class="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <p class="text-sm text-muted-foreground">Completed</p>
                                        <p class="text-sm font-medium">{{ formatFullDate(execution.completed_at) }}</p>
                                    </div>
                                </div>
                                <Separator v-if="duration" />
                                <div v-if="duration" class="flex items-center gap-3">
                                    <Clock class="h-5 w-5 text-muted-foreground" />
                                    <div>
                                        <p class="text-sm text-muted-foreground">Duration</p>
                                        <p class="text-sm font-medium">{{ duration }}</p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <!-- Task Payload -->
                        <Card v-if="execution.task?.payload">
                            <CardHeader>
                                <CardTitle>Task Payload</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <pre class="whitespace-pre-wrap rounded-lg bg-muted p-3 text-xs font-mono">{{ JSON.stringify(execution.task.payload, null, 2) }}</pre>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
