<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Pencil, Trash2 } from 'lucide-vue-next';
import type { Tool, Agent } from '@/types';

const props = defineProps<{
    tool: Tool & { agents?: Agent[] };
}>();

const deleteTool = () => {
    if (confirm(`Are you sure you want to delete "${props.tool.name}"?`)) {
        router.delete(`/tools/${props.tool.id}`);
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

const formatDate = (date: string | null) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <AppLayout :title="tool.name">
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link href="/tools">
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-3xl font-bold">{{ tool.name }}</h1>
                            <Badge :class="getTypeColor(tool.type)" variant="secondary">
                                {{ tool.type }}
                            </Badge>
                        </div>
                    </div>
                </div>
                <div class="flex gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="`/tools/${tool.id}/edit`">
                            <Pencil class="h-4 w-4 mr-2" />
                            Edit
                        </Link>
                    </Button>
                    <Button variant="destructive" @click="deleteTool">
                        <Trash2 class="h-4 w-4 mr-2" />
                        Delete
                    </Button>
                </div>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <pre class="p-4 rounded-lg bg-muted text-sm font-mono whitespace-pre-wrap overflow-auto max-h-[400px]">{{ JSON.stringify(tool.config, null, 2) }}</pre>
                        </CardContent>
                    </Card>
                </div>

                <div class="space-y-6">
                    <Card>
                        <CardHeader>
                            <CardTitle>Details</CardTitle>
                        </CardHeader>
                        <CardContent class="space-y-4">
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Type</p>
                                <p class="font-medium capitalize">{{ tool.type }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Created</p>
                                <p class="font-medium">{{ formatDate(tool.created_at) }}</p>
                            </div>
                            <div>
                                <p class="text-sm font-medium text-muted-foreground">Last Updated</p>
                                <p class="font-medium">{{ formatDate(tool.updated_at) }}</p>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Used By ({{ tool.agents?.length || 0 }})</CardTitle>
                            <CardDescription>Agents using this tool</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div v-if="!tool.agents?.length" class="text-center text-muted-foreground py-4">
                                No agents using this tool
                            </div>
                            <div v-else class="space-y-2">
                                <Link
                                    v-for="agent in tool.agents"
                                    :key="agent.id"
                                    :href="`/agents/${agent.id}`"
                                    class="flex items-center justify-between rounded-lg border p-3 hover:bg-muted transition-colors"
                                >
                                    <span class="font-medium">{{ agent.name }}</span>
                                </Link>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
