<script setup lang="ts">
import { ref, computed } from 'vue';
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
import { ArrowLeft, Save, X } from 'lucide-vue-next';
import type { Auth } from '@/types/auth';

interface Props {
    auth: Auth;
}

defineProps<Props>();

const form = ref({
    name: '',
    type: 'api' as 'api' | 'function' | 'command',
    config: {
        url: '',
        method: 'GET' as 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH',
        headers: '{}',
        code: '',
        command: '',
    },
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);

const configFields = computed(() => {
    switch (form.value.type) {
        case 'api':
            return ['url', 'method', 'headers'];
        case 'function':
            return ['code'];
        case 'command':
            return ['command'];
        default:
            return [];
    }
});

const buildConfig = () => {
    switch (form.value.type) {
        case 'api':
            let headers = {};
            try {
                headers = JSON.parse(form.value.config.headers);
            } catch (e) {
                errors.value['config.headers'] = 'Invalid JSON format';
                return null;
            }
            return {
                url: form.value.config.url,
                method: form.value.config.method,
                headers,
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

const submit = () => {
    processing.value = true;
    errors.value = {};

    const config = buildConfig();
    if (config === null) {
        processing.value = false;
        return;
    }

    router.post(
        '/tools',
        {
            name: form.value.name,
            type: form.value.type,
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
    <AuthenticatedLayout title="Create Tool" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center gap-4">
                <Button as-child variant="ghost" size="sm">
                    <Link href="/tools">
                        <ArrowLeft class="mr-2 h-4 w-4" />
                        Back
                    </Link>
                </Button>
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Create Tool</h1>
                    <p class="text-muted-foreground">Configure a new tool for your agents</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <!-- Basic Information -->
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Set the tool's name and type</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Name *</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    placeholder="My Tool"
                                    :class="{ 'border-destructive': errors.name }"
                                />
                                <p v-if="errors.name" class="text-sm text-destructive">{{ errors.name }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="type">Type *</Label>
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
                                <p v-if="errors.type" class="text-sm text-destructive">{{ errors.type }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- API Configuration -->
                <Card v-if="form.type === 'api'">
                    <CardHeader>
                        <CardTitle>API Configuration</CardTitle>
                        <CardDescription>Configure the API endpoint settings</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="url">URL *</Label>
                                <Input
                                    id="url"
                                    v-model="form.config.url"
                                    placeholder="https://api.example.com/endpoint"
                                    :class="{ 'border-destructive': errors['config.url'] }"
                                />
                                <p v-if="errors['config.url']" class="text-sm text-destructive">{{ errors['config.url'] }}</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="method">HTTP Method</Label>
                                <Select v-model="form.config.method">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select method" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="GET">GET</SelectItem>
                                        <SelectItem value="POST">POST</SelectItem>
                                        <SelectItem value="PUT">PUT</SelectItem>
                                        <SelectItem value="PATCH">PATCH</SelectItem>
                                        <SelectItem value="DELETE">DELETE</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="headers">Headers (JSON)</Label>
                            <Textarea
                                id="headers"
                                v-model="form.config.headers"
                                placeholder='{"Authorization": "Bearer token", "Content-Type": "application/json"}'
                                rows="4"
                                class="font-mono text-sm"
                                :class="{ 'border-destructive': errors['config.headers'] }"
                            />
                            <p v-if="errors['config.headers']" class="text-sm text-destructive">{{ errors['config.headers'] }}</p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Function Configuration -->
                <Card v-if="form.type === 'function'">
                    <CardHeader>
                        <CardTitle>Function Configuration</CardTitle>
                        <CardDescription>Define the function code</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <Label for="code">Code *</Label>
                            <Textarea
                                id="code"
                                v-model="form.config.code"
                                placeholder="function execute(input) { return input; }"
                                rows="10"
                                class="font-mono text-sm"
                                :class="{ 'border-destructive': errors['config.code'] }"
                            />
                            <p v-if="errors['config.code']" class="text-sm text-destructive">{{ errors['config.code'] }}</p>
                            <p class="text-sm text-muted-foreground">
                                Write the function code that will be executed by the agent
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Command Configuration -->
                <Card v-if="form.type === 'command'">
                    <CardHeader>
                        <CardTitle>Command Configuration</CardTitle>
                        <CardDescription>Define the command template</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="space-y-2">
                            <Label for="command">Command Template *</Label>
                            <Textarea
                                id="command"
                                v-model="form.config.command"
                                placeholder="echo 'Hello, {{input}}'"
                                rows="4"
                                class="font-mono text-sm"
                                :class="{ 'border-destructive': errors['config.command'] }"
                            />
                            <p v-if="errors['config.command']" class="text-sm text-destructive">{{ errors['config.command'] }}</p>
                            <p class="text-sm text-muted-foreground">
                                Use {'{{input}}'} as a placeholder for dynamic values
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <!-- Actions -->
                <div class="flex justify-end gap-4">
                    <Button as-child variant="outline">
                        <Link href="/tools">
                            <X class="mr-2 h-4 w-4" />
                            Cancel
                        </Link>
                    </Button>
                    <Button type="submit" :disabled="processing">
                        <Save class="mr-2 h-4 w-4" />
                        {{ processing ? 'Creating...' : 'Create Tool' }}
                    </Button>
                </div>
            </form>
        </div>
    </AuthenticatedLayout>
</template>
