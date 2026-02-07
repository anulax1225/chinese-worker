<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
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
import { ArrowLeft } from 'lucide-vue-next';
import { update } from '@/actions/App/Http/Controllers/Api/V1/ToolController';
import type { Tool, ApiToolConfig, FunctionToolConfig, CommandToolConfig } from '@/types';

const props = defineProps<{
    tool: Tool;
}>();

const getInitialConfig = () => {
    const config = props.tool.config || {};
    return {
        url: (config as ApiToolConfig).url || '',
        method: ((config as ApiToolConfig).method || 'GET') as 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH',
        headers: (config as ApiToolConfig).headers || {},
        code: (config as FunctionToolConfig).code || '',
        command: (config as CommandToolConfig).command || '',
    };
};

const form = ref({
    name: props.tool.name,
    type: props.tool.type as 'api' | 'function' | 'command',
    config: getInitialConfig(),
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);

const getConfigForType = () => {
    switch (form.value.type) {
        case 'api':
            return {
                url: form.value.config.url,
                method: form.value.config.method,
                headers: form.value.config.headers,
            };
        case 'function':
            return {
                code: form.value.config.code,
            };
        case 'command':
            return {
                command: form.value.config.command,
            };
        default:
            return {};
    }
};

const submit = async () => {
    processing.value = true;
    errors.value = {};

    try {
        const response = await fetch(update.url(props.tool.id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify({
                name: form.value.name,
                type: form.value.type,
                config: getConfigForType(),
            }),
        });

        if (response.ok) {
            router.visit(`/tools/${props.tool.id}`);
        } else if (response.status === 422) {
            const data = await response.json();
            errors.value = data.errors || {};
        }
    } finally {
        processing.value = false;
    }
};
</script>

<template>
    <AppLayout :title="`Edit ${tool.name}`">
        <div class="space-y-6">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="`/tools/${tool.id}`">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-3xl font-bold">Edit Tool</h1>
                    <p class="text-muted-foreground">Update {{ tool.name }}</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Name</Label>
                                <Input id="name" v-model="form.name" required />
                                <p v-if="errors.name" class="text-sm text-destructive">
                                    {{ errors.name }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="type">Type</Label>
                                <Select v-model="form.type">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="api">API</SelectItem>
                                        <SelectItem value="function">Function</SelectItem>
                                        <SelectItem value="command">Command</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="form.type === 'api'">
                    <CardHeader>
                        <CardTitle>API Configuration</CardTitle>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <Label for="url">URL</Label>
                            <Input id="url" v-model="form.config.url" required />
                        </div>
                        <div class="space-y-2">
                            <Label for="method">Method</Label>
                            <Select v-model="form.config.method">
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="GET">GET</SelectItem>
                                    <SelectItem value="POST">POST</SelectItem>
                                    <SelectItem value="PUT">PUT</SelectItem>
                                    <SelectItem value="DELETE">DELETE</SelectItem>
                                    <SelectItem value="PATCH">PATCH</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="form.type === 'function'">
                    <CardHeader>
                        <CardTitle>Function Code</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Textarea
                            v-model="form.config.code"
                            rows="10"
                            class="font-mono text-sm"
                            required
                        />
                    </CardContent>
                </Card>

                <Card v-if="form.type === 'command'">
                    <CardHeader>
                        <CardTitle>Command Configuration</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Label for="command">Command</Label>
                            <Input id="command" v-model="form.config.command" required />
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-4">
                    <Button variant="outline" type="button" as-child>
                        <Link :href="`/tools/${tool.id}`">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Saving...' : 'Save Changes' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
