<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Separator } from '@/components/ui/separator';
import {
    ArrowLeft,
    Pencil,
    Trash2,
    Clock,
    Globe,
    Code,
    Terminal,
    Bot,
} from 'lucide-vue-next';
import { format } from 'date-fns';
import type { Tool, Agent } from '@/types/models';
import type { Auth } from '@/types/auth';

interface Props {
    auth: Auth;
    tool: Tool & { agents?: Agent[] };
}

const props = defineProps<Props>();

const getTypeVariant = (type: string) => {
    switch (type) {
        case 'api':
            return 'default';
        case 'function':
            return 'secondary';
        case 'command':
            return 'outline';
        default:
            return 'outline';
    }
};

const getTypeIcon = (type: string) => {
    switch (type) {
        case 'api':
            return Globe;
        case 'function':
            return Code;
        case 'command':
            return Terminal;
        default:
            return Code;
    }
};

const formatFullDate = (date: string) => {
    return format(new Date(date), 'PPpp');
};

const deleteTool = () => {
    if (confirm(`Are you sure you want to delete "${props.tool.name}"? This action cannot be undone.`)) {
        router.delete(`/tools/${props.tool.id}`);
    }
};
</script>

<template>
    <AuthenticatedLayout :title="tool.name" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Button as-child variant="ghost" size="sm">
                        <Link href="/tools">
                            <ArrowLeft class="mr-2 h-4 w-4" />
                            Back
                        </Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-3xl font-bold tracking-tight">{{ tool.name }}</h1>
                            <Badge :variant="getTypeVariant(tool.type)">
                                {{ tool.type }}
                            </Badge>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button as-child variant="outline">
                        <Link :href="`/tools/${tool.id}/edit`">
                            <Pencil class="mr-2 h-4 w-4" />
                            Edit
                        </Link>
                    </Button>
                    <Button variant="destructive" @click="deleteTool">
                        <Trash2 class="mr-2 h-4 w-4" />
                        Delete
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Configuration -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <component :is="getTypeIcon(tool.type)" class="h-5 w-5" />
                                Configuration
                            </CardTitle>
                            <CardDescription>
                                {{ tool.type === 'api' ? 'API endpoint configuration' : tool.type === 'function' ? 'Function code' : 'Command template' }}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <!-- API Config -->
                            <div v-if="tool.type === 'api'" class="space-y-4">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <p class="text-sm font-medium text-muted-foreground">URL</p>
                                        <code class="mt-1 block rounded bg-muted px-2 py-1 text-sm">
                                            {{ (tool.config as any)?.url || 'Not set' }}
                                        </code>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-muted-foreground">Method</p>
                                        <Badge variant="outline" class="mt-1">
                                            {{ (tool.config as any)?.method || 'GET' }}
                                        </Badge>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-sm font-medium text-muted-foreground mb-2">Headers</p>
                                    <pre class="whitespace-pre-wrap rounded-lg bg-muted p-4 text-sm font-mono">{{ JSON.stringify((tool.config as any)?.headers || {}, null, 2) }}</pre>
                                </div>
                            </div>

                            <!-- Function Config -->
                            <div v-else-if="tool.type === 'function'">
                                <p class="text-sm font-medium text-muted-foreground mb-2">Code</p>
                                <pre class="whitespace-pre-wrap rounded-lg bg-muted p-4 text-sm font-mono">{{ (tool.config as any)?.code || 'No code defined' }}</pre>
                            </div>

                            <!-- Command Config -->
                            <div v-else-if="tool.type === 'command'">
                                <p class="text-sm font-medium text-muted-foreground mb-2">Command Template</p>
                                <pre class="whitespace-pre-wrap rounded-lg bg-muted p-4 text-sm font-mono">{{ (tool.config as any)?.command || 'No command defined' }}</pre>
                            </div>
                        </CardContent>
                    </Card>

                    <!-- Agents Using This Tool -->
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2">
                                <Bot class="h-5 w-5" />
                                Agents Using This Tool
                            </CardTitle>
                            <CardDescription>Agents that have this tool attached</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!tool.agents || tool.agents.length === 0" class="text-center py-8 text-muted-foreground">
                                No agents are using this tool yet.
                            </div>
                            <div v-else class="space-y-2">
                                <div
                                    v-for="agent in tool.agents"
                                    :key="agent.id"
                                    class="flex items-center justify-between rounded-lg border p-3"
                                >
                                    <div>
                                        <p class="font-medium">{{ agent.name }}</p>
                                        <p class="text-sm text-muted-foreground">{{ agent.description || 'No description' }}</p>
                                    </div>
                                    <Button as-child variant="ghost" size="sm">
                                        <Link :href="`/agents/${agent.id}`">View</Link>
                                    </Button>
                                </div>
                            </div>
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
                                <component :is="getTypeIcon(tool.type)" class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">Type</p>
                                    <Badge :variant="getTypeVariant(tool.type)">{{ tool.type }}</Badge>
                                </div>
                            </div>
                            <Separator />
                            <div class="flex items-center gap-3">
                                <Bot class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">Used By</p>
                                    <p class="text-sm font-medium">{{ tool.agents?.length || 0 }} agent(s)</p>
                                </div>
                            </div>
                            <Separator />
                            <div class="flex items-center gap-3">
                                <Clock class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">Created</p>
                                    <p class="text-sm font-medium">{{ formatFullDate(tool.created_at) }}</p>
                                </div>
                            </div>
                            <Separator />
                            <div class="flex items-center gap-3">
                                <Clock class="h-5 w-5 text-muted-foreground" />
                                <div>
                                    <p class="text-sm text-muted-foreground">Last Updated</p>
                                    <p class="text-sm font-medium">{{ formatFullDate(tool.updated_at) }}</p>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
