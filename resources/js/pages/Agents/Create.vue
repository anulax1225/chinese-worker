<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import { ArrowLeft, Save, X } from 'lucide-vue-next';
import type { Tool } from '@/types/models';
import type { Auth } from '@/types/auth';

interface Props {
    auth: Auth;
    tools: Tool[];
    backends: string[];
    defaultBackend: string;
}

const props = defineProps<Props>();

const form = ref({
    name: '',
    description: '',
    code: '',
    config: '{}',
    status: 'active',
    ai_backend: props.defaultBackend,
    tool_ids: [] as number[],
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);

const toggleTool = (toolId: number) => {
    const index = form.value.tool_ids.indexOf(toolId);
    if (index === -1) {
        form.value.tool_ids.push(toolId);
    } else {
        form.value.tool_ids.splice(index, 1);
    }
};

const submit = () => {
    processing.value = true;
    errors.value = {};

    let config = {};
    try {
        config = JSON.parse(form.value.config);
    } catch (e) {
        errors.value.config = 'Invalid JSON format';
        processing.value = false;
        return;
    }

    router.post(
        '/agents',
        {
            ...form.value,
            config,
        },
        {
            onError: (errs) => {
                errors.value = errs;
            },
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <AuthenticatedLayout title="Create Agent" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center gap-4">
                <Button as-child variant="ghost" size="sm">
                    <Link href="/agents">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Back
                    </Link>
                </Button>
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Create Agent</h1>
                    <p class="text-muted-foreground">Configure a new AI agent</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Basic Information -->
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Set the agent's name and description</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <Label for="name">Name *</Label>
                            <Input
                                id="name"
                                v-model="form.name"
                                placeholder="My Agent"
                                :class="{ 'border-destructive': errors.name }"
                            />
                            <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p>
                        </div>

                        <div class="space-y-2">
                            <Label for="description">Description</Label>
                            <Textarea
                                id="description"
                                v-model="form.description"
                                placeholder="Describe what this agent does..."
                                rows="3"
                            />
                            <p v-if="errors.description" class="text-sm text-destructive">{{ errors.description }}</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Agent Configuration -->
                <Card>
                    <CardHeader>
                        <CardTitle>Configuration</CardTitle>
                        <CardDescription>Configure the agent's behavior</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
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

                            <div class="space-y-2">
                                <Label for="ai_backend">AI Backend</Label>
                                <Select v-model="form.ai_backend">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select backend" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="backend in backends"
                                            :key="backend"
                                            :value="backend"
                                        >
                                            {{ backend }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="code">Instructions / System Prompt *</Label>
                            <Textarea
                                id="code"
                                v-model="form.code"
                                placeholder="You are a helpful assistant that..."
                                rows="10"
                                class="font-mono text-sm"
                                :class="{ 'border-destructive': errors.code }"
                            />
                            <p v-if="errors.code" class="text-sm text-destructive">{{ errors.code }}</p>
                            <p class="text-sm text-muted-foreground">
                                Define the agent's behavior, personality, and instructions
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="config">Additional Config (JSON)</Label>
                            <Textarea
                                id="config"
                                v-model="form.config"
                                placeholder="{}"
                                rows="4"
                                class="font-mono text-sm"
                                :class="{ 'border-destructive': errors.config }"
                            />
                            <p v-if="errors.config" class="text-sm text-destructive">{{ errors.config }}</p>
                            <p class="text-sm text-muted-foreground">
                                Optional JSON configuration for advanced settings
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Tools -->
                <Card>
                    <CardHeader>
                        <CardTitle>Tools</CardTitle>
                        <CardDescription>Select tools the agent can use</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="tools.length === 0" class="text-center py-4 text-muted-foreground">
                            No tools available.
                            <Link href="/tools/create" class="text-primary hover:underline">
                                Create one first
                            </Link>
                        </div>
                        <div v-else class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                            <div
                                v-for="tool in tools"
                                :key="tool.id"
                                class="flex items-center justify-between rounded-lg border p-3"
                                :class="{ 'border-primary bg-primary/5': form.tool_ids.includes(tool.id) }"
                            >
                                <div class="flex items-center gap-3">
                                    <Switch
                                        :checked="form.tool_ids.includes(tool.id)"
                                        @update:checked="toggleTool(tool.id)"
                                    />
                                    <div>
                                        <p class="font-medium">{{ tool.name }}</p>
                                        <Badge variant="outline" class="text-xs">{{ tool.type }}</Badge>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="flex justify-end gap-4">
                    <Button as-child variant="outline">
                        <Link href="/agents">
                            <X class="mr-2 h-4 w-4" />
                            Cancel
                        </Link>
                    </Button>
                    <Button type="submit" :disabled="processing">
                        <Save class="mr-2 h-4 w-4" />
                        {{ processing ? 'Creating...' : 'Create Agent' }}
                    </Button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
