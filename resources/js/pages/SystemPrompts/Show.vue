<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Pencil, Trash2, Bot } from 'lucide-vue-next';
import { destroy } from '@/actions/App/Http/Controllers/Api/V1/SystemPromptController';
import type { SystemPrompt, Agent } from '@/types';

const props = defineProps<{
    prompt: SystemPrompt & { agents?: Agent[] };
}>();

const deleting = ref(false);

const deletePrompt = async () => {
    if (!confirm(`Are you sure you want to delete "${props.prompt.name}"?`)) {
        return;
    }

    deleting.value = true;
    try {
        const response = await fetch(destroy.url(props.prompt.id), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });

        if (response.ok) {
            router.visit('/system-prompts');
        }
    } finally {
        deleting.value = false;
    }
};
</script>

<template>
    <AppLayout :title="prompt.name">
        <div class="max-w-3xl mx-auto space-y-6">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <Button variant="ghost" size="icon" as-child>
                        <Link href="/system-prompts">
                            <ArrowLeft class="h-4 w-4" />
                        </Link>
                    </Button>
                    <div>
                        <div class="flex items-center gap-3">
                            <h1 class="text-3xl font-bold">{{ prompt.name }}</h1>
                            <Badge
                                variant="outline"
                                :class="[
                                    prompt.is_active
                                        ? 'bg-green-500/10 text-green-600 border-green-500/20'
                                        : 'bg-gray-500/10 text-gray-600 border-gray-500/20'
                                ]"
                            >
                                {{ prompt.is_active ? 'Active' : 'Inactive' }}
                            </Badge>
                        </div>
                        <p class="text-muted-foreground font-mono">{{ prompt.slug }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="`/system-prompts/${prompt.id}/edit`">
                            <Pencil class="h-4 w-4 mr-2" />
                            Edit
                        </Link>
                    </Button>
                    <Button
                        variant="destructive"
                        @click="deletePrompt"
                        :disabled="deleting"
                    >
                        <Trash2 class="h-4 w-4 mr-2" />
                        {{ deleting ? 'Deleting...' : 'Delete' }}
                    </Button>
                </div>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Template</CardTitle>
                    <CardDescription>The Blade template content</CardDescription>
                </CardHeader>
                <CardContent>
                    <pre class="p-4 bg-muted rounded-lg overflow-x-auto text-sm font-mono whitespace-pre-wrap">{{ prompt.template }}</pre>
                </CardContent>
            </Card>

            <Card v-if="prompt.required_variables?.length">
                <CardHeader>
                    <CardTitle>Required Variables</CardTitle>
                    <CardDescription>Variables that must be provided when using this prompt</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <div
                            v-for="variable in prompt.required_variables"
                            :key="variable"
                            class="flex items-center justify-between p-3 bg-muted/50 rounded-lg"
                        >
                            <code class="text-sm font-mono">{{ variable }}</code>
                            <span
                                v-if="prompt.default_values?.[variable]"
                                class="text-sm text-muted-foreground"
                            >
                                Default: {{ prompt.default_values[variable] }}
                            </span>
                            <span v-else class="text-sm text-muted-foreground italic">
                                No default
                            </span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card v-if="prompt.agents?.length">
                <CardHeader>
                    <CardTitle>Used By Agents</CardTitle>
                    <CardDescription>Agents using this system prompt</CardDescription>
                </CardHeader>
                <CardContent>
                    <div class="space-y-2">
                        <Link
                            v-for="agent in prompt.agents"
                            :key="agent.id"
                            :href="`/agents/${agent.id}`"
                            class="flex items-center gap-3 p-3 bg-muted/50 rounded-lg hover:bg-muted transition-colors"
                        >
                            <Bot class="h-4 w-4 text-muted-foreground" />
                            <span class="font-medium">{{ agent.name }}</span>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Metadata</CardTitle>
                </CardHeader>
                <CardContent>
                    <dl class="grid grid-cols-2 gap-4 text-sm">
                        <div>
                            <dt class="text-muted-foreground">Created</dt>
                            <dd>{{ new Date(prompt.created_at).toLocaleString() }}</dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground">Updated</dt>
                            <dd>{{ new Date(prompt.updated_at).toLocaleString() }}</dd>
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
