<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { ArrowLeft } from 'lucide-vue-next';
import type { Tool } from '@/types';

const props = defineProps<{
    tools: Tool[];
    backends: string[];
    defaultBackend: string;
}>();

const form = useForm({
    name: '',
    description: '',
    code: '// System prompt for your agent\nYou are a helpful assistant.',
    config: {} as Record<string, unknown>,
    status: 'active' as const,
    ai_backend: props.defaultBackend,
    tool_ids: [] as number[],
});

const submit = () => {
    form.post('/agents');
};

const toggleTool = (toolId: number) => {
    const index = form.tool_ids.indexOf(toolId);
    if (index === -1) {
        form.tool_ids.push(toolId);
    } else {
        form.tool_ids.splice(index, 1);
    }
};
</script>

<template>
    <AppLayout title="Create Agent">
        <div class="space-y-4">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link href="/agents">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-xl font-semibold">Create Agent</h1>
                    <p class="text-sm text-muted-foreground">Configure a new AI agent</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Set up the agent's identity and configuration</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    placeholder="My Assistant"
                                    required
                                />
                                <p v-if="form.errors.name" class="text-sm text-destructive">
                                    {{ form.errors.name }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="ai_backend">AI Backend</Label>
                                <Select v-model="form.ai_backend">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select backend" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem v-for="backend in backends" :key="backend" :value="backend">
                                            {{ backend }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="form.errors.ai_backend" class="text-sm text-destructive">
                                    {{ form.errors.ai_backend }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="A helpful assistant that..."
                                rows="2"
                            />
                            <p v-if="form.errors.description" class="text-sm text-destructive">
                                {{ form.errors.description }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="status">Status</Label>
                            <Select v-model="form.status">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select status" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">Active</SelectItem>
                                    <SelectItem value="inactive">Inactive</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>System Prompt</CardTitle>
                        <CardDescription>Define how your agent should behave</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Textarea
                                id="code"
                                v-model="form.code"
                                placeholder="You are a helpful assistant..."
                                rows="10"
                                class="font-mono text-sm"
                            />
                            <p v-if="form.errors.code" class="text-sm text-destructive">
                                {{ form.errors.code }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="tools.length > 0">
                    <CardHeader>
                        <CardTitle>Tools</CardTitle>
                        <CardDescription>Select tools this agent can use</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="grid gap-3">
                            <div
                                v-for="tool in tools"
                                :key="tool.id"
                                class="flex items-center justify-between rounded-lg border p-4"
                            >
                                <div>
                                    <p class="font-medium">{{ tool.name }}</p>
                                    <p class="text-sm text-muted-foreground">{{ tool.type }}</p>
                                </div>
                                <Switch
                                    :checked="form.tool_ids.includes(tool.id as number)"
                                    @update:checked="toggleTool(tool.id as number)"
                                />
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-4">
                    <Button variant="outline" type="button" as-child>
                        <Link href="/agents">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Agent' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
