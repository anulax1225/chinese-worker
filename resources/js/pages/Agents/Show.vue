<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Pencil, Trash2, MessageSquare } from 'lucide-vue-next';
import type { Agent, Tool } from '@/types';

const props = defineProps<{
    agent: Agent & { tools: Tool[] };
}>();

const deleteAgent = () => {
    if (confirm(`Are you sure you want to delete "${props.agent.name}"?`)) {
        router.delete(`/agents/${props.agent.id}`);
    }
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-green-500',
        inactive: 'bg-gray-500',
        error: 'bg-red-500',
        pending: 'bg-yellow-500',
        running: 'bg-blue-500',
        completed: 'bg-green-500',
        failed: 'bg-red-500',
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
    <AppLayout :title="agent.name">
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link href="/agents">
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-xl font-semibold">{{ agent.name }}</h1>
                            <Badge :class="getStatusColor(agent.status)" variant="secondary">
                                {{ agent.status }}
                            </Badge>
                        </div>
                        <p v-if="agent.description" class="text-sm text-muted-foreground">{{ agent.description }}</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="`/conversations/create?agent_id=${agent.id}`">
                            <MessageSquare class="h-4 w-4 mr-2" />
                            New Conversation
                        </Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link :href="`/agents/${agent.id}/edit`">
                            <Pencil class="h-4 w-4 mr-2" />
                            Edit
                        </Link>
                    </Button>
                    <Button variant="destructive" @click="deleteAgent">
                        <Trash2 class="h-4 w-4 mr-2" />
                        Delete
                    </Button>
                </div>
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>System Prompt</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre class="p-4 rounded-lg bg-muted text-sm font-mono whitespace-pre-wrap overflow-auto max-h-[400px]">{{ agent.code }}</pre>
                        </CardContent>
                    </Card>

                </div>

                <div class="space-y-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">AI Backend</p>
                                <p class="font-medium">{{ agent.ai_backend }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Created</p>
                                <p class="font-medium">{{ formatDate(agent.created_at) }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Last Updated</p>
                                <p class="font-medium">{{ formatDate(agent.updated_at) }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Tools ({{ agent.tools?.length || 0 }})</CardTitle>
                            <CardDescription>Tools this agent can use</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!agent.tools?.length" class="text-center text-muted-foreground py-4">
                                No tools assigned
                            </div>
                            <div v-else class="space-y-2">
                                <Link
                                    v-for="tool in agent.tools"
                                    :key="tool.id"
                                    :href="`/tools/${tool.id}`"
                                    class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted transition-colors"
                                >
                                    <span class="font-medium">{{ tool.name }}</span>
                                    <Badge variant="outline">{{ tool.type }}</Badge>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
