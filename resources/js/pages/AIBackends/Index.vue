<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
    RefreshCw,
    CheckCircle,
    XCircle,
    AlertCircle,
    Cpu,
    Star,
    Zap,
    MessageSquare,
    Eye,
    Code,
} from 'lucide-vue-next';
import type { Auth } from '@/types/auth';

interface AIBackend {
    name: string;
    driver: string;
    is_default: boolean;
    model: string | null;
    capabilities: {
        streaming: boolean;
        function_calling: boolean;
        vision: boolean;
        embeddings?: boolean;
    };
    models: Array<{
        name: string;
        modified_at?: string;
        size?: number;
    }>;
    status: 'connected' | 'error' | 'unknown';
    error?: string;
}

interface Props {
    auth: Auth;
    backends: AIBackend[];
    defaultBackend: string;
}

const props = defineProps<Props>();

const getStatusVariant = (status: string) => {
    switch (status) {
        case 'connected':
            return 'default';
        case 'error':
            return 'destructive';
        default:
            return 'outline';
    }
};

const getStatusIcon = (status: string) => {
    switch (status) {
        case 'connected':
            return CheckCircle;
        case 'error':
            return XCircle;
        default:
            return AlertCircle;
    }
};

const formatSize = (bytes: number | undefined) => {
    if (!bytes) return 'Unknown';
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return `${size.toFixed(1)} ${units[unitIndex]}`;
};

const refresh = () => {
    router.reload();
};
</script>

<template>
    <AuthenticatedLayout title="AI Backends" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">AI Backends</h1>
                    <p class="text-muted-foreground">Manage and monitor your AI backend configurations</p>
                </div>
                <Button variant="outline" @click="refresh">
                    <RefreshCw class="mr-2 h-4 w-4" />
                    Refresh
                </Button>
            </div>

            <!-- Backends Grid -->
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <Card v-for="backend in backends" :key="backend.name" class="relative">
                    <div v-if="backend.is_default" class="absolute -top-2 -right-2">
                        <Badge class="flex items-center gap-1 bg-yellow-500 text-yellow-950">
                            <Star class="h-3 w-3" />
                            Default
                        </Badge>
                    </div>
                    <CardHeader>
                        <div class="flex items-center justify-between">
                            <CardTitle class="flex items-center gap-2">
                                <Cpu class="h-5 w-5" />
                                {{ backend.name }}
                            </CardTitle>
                            <Badge :variant="getStatusVariant(backend.status)" class="flex items-center gap-1">
                                <component :is="getStatusIcon(backend.status)" class="h-3 w-3" />
                                {{ backend.status }}
                            </Badge>
                        </div>
                        <CardDescription>
                            Driver: {{ backend.driver }}
                            <span v-if="backend.model"> | Model: {{ backend.model }}</span>
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <!-- Error Message -->
                        <div v-if="backend.error" class="rounded-lg bg-destructive/10 border border-destructive/20 p-3">
                            <p class="text-sm text-destructive">{{ backend.error }}</p>
                        </div>

                        <!-- Capabilities -->
                        <div>
                            <p class="text-sm font-medium text-muted-foreground mb-2">Capabilities</p>
                            <div class="flex flex-wrap gap-2">
                                <Badge
                                    v-if="backend.capabilities.streaming"
                                    variant="outline"
                                    class="flex items-center gap-1"
                                >
                                    <Zap class="h-3 w-3" />
                                    Streaming
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.function_calling"
                                    variant="outline"
                                    class="flex items-center gap-1"
                                >
                                    <Code class="h-3 w-3" />
                                    Function Calling
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.vision"
                                    variant="outline"
                                    class="flex items-center gap-1"
                                >
                                    <Eye class="h-3 w-3" />
                                    Vision
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.embeddings"
                                    variant="outline"
                                    class="flex items-center gap-1"
                                >
                                    <MessageSquare class="h-3 w-3" />
                                    Embeddings
                                </Badge>
                                <span
                                    v-if="Object.keys(backend.capabilities).length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    No capabilities reported
                                </span>
                            </div>
                        </div>

                        <!-- Available Models (for Ollama) -->
                        <div v-if="backend.models && backend.models.length > 0">
                            <p class="text-sm font-medium text-muted-foreground mb-2">Available Models</p>
                            <div class="max-h-40 overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead class="text-xs">Name</TableHead>
                                            <TableHead class="text-xs">Size</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow v-for="model in backend.models" :key="model.name">
                                            <TableCell class="text-xs font-medium">{{ model.name }}</TableCell>
                                            <TableCell class="text-xs text-muted-foreground">{{ formatSize(model.size) }}</TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Configuration Info -->
            <Card>
                <CardHeader>
                    <CardTitle>Configuration</CardTitle>
                    <CardDescription>
                        AI backends are configured in <code class="rounded bg-muted px-1.5 py-0.5 text-sm">config/ai.php</code>
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-3">
                            <div class="rounded-lg border p-4">
                                <h3 class="font-medium mb-2">Ollama (Local)</h3>
                                <p class="text-sm text-muted-foreground">
                                    Run models locally. Configure <code class="rounded bg-muted px-1 text-xs">OLLAMA_BASE_URL</code> and <code class="rounded bg-muted px-1 text-xs">OLLAMA_MODEL</code> in your <code class="rounded bg-muted px-1 text-xs">.env</code> file.
                                </p>
                            </div>
                            <div class="rounded-lg border p-4">
                                <h3 class="font-medium mb-2">Anthropic Claude</h3>
                                <p class="text-sm text-muted-foreground">
                                    Set <code class="rounded bg-muted px-1 text-xs">ANTHROPIC_API_KEY</code> in your <code class="rounded bg-muted px-1 text-xs">.env</code> file to enable Claude models.
                                </p>
                            </div>
                            <div class="rounded-lg border p-4">
                                <h3 class="font-medium mb-2">OpenAI</h3>
                                <p class="text-sm text-muted-foreground">
                                    Set <code class="rounded bg-muted px-1 text-xs">OPENAI_API_KEY</code> in your <code class="rounded bg-muted px-1 text-xs">.env</code> file to enable GPT models.
                                </p>
                            </div>
                        </div>
                        <Separator />
                        <p class="text-sm text-muted-foreground">
                            To change the default backend, set <code class="rounded bg-muted px-1.5 py-0.5 text-sm">AI_BACKEND={{ defaultBackend }}</code> in your <code class="rounded bg-muted px-1.5 py-0.5 text-sm">.env</code> file.
                        </p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
