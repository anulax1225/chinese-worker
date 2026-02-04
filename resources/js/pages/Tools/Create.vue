<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
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

const form = useForm({
    name: '',
    type: 'api' as 'api' | 'function' | 'command',
    config: {
        url: '',
        method: 'GET' as 'GET' | 'POST' | 'PUT' | 'DELETE' | 'PATCH',
        headers: {} as Record<string, string>,
        code: '',
        command: '',
    },
});

const submit = () => {
    const config = getConfigForType();
    form.transform(data => ({
        ...data,
        config,
    })).post('/tools');
};

const getConfigForType = () => {
    switch (form.type) {
        case 'api':
            return {
                url: form.config.url,
                method: form.config.method,
                headers: form.config.headers,
            };
        case 'function':
            return {
                code: form.config.code,
            };
        case 'command':
            return {
                command: form.config.command,
            };
        default:
            return {};
    }
};

const typeDescriptions = {
    api: 'Make HTTP requests to external APIs',
    function: 'Execute custom JavaScript/TypeScript code',
    command: 'Run shell commands on the server',
};
</script>

<template>
    <AppLayout title="Create Tool">
        <div class="space-y-6">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link href="/tools">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-3xl font-bold">Create Tool</h1>
                    <p class="text-muted-foreground">Define a new tool for your agents</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Set up the tool's identity</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    placeholder="my_tool"
                                    required
                                />
                                <p v-if="form.errors.name" class="text-sm text-destructive">
                                    {{ form.errors.name }}
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
                                <p class="text-sm text-muted-foreground">{{ typeDescriptions[form.type] }}</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <!-- API Configuration -->
                <Card v-if="form.type === 'api'">
                    <CardHeader>
                        <CardTitle>API Configuration</CardTitle>
                        <CardDescription>Configure the API endpoint</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2 sm:col-span-2">
                                <Label for="url">URL</Label>
                                <Input
                                    id="url"
                                    v-model="form.config.url"
                                    placeholder="https://api.example.com/endpoint"
                                    required
                                />
                            </div>
                            <div class="space-y-2">
                                <Label for="method">Method</Label>
                                <Select v-model="form.config.method">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select method" />
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
                        </div>
                    </CardContent>
                </Card>

                <!-- Function Configuration -->
                <Card v-if="form.type === 'function'">
                    <CardHeader>
                        <CardTitle>Function Code</CardTitle>
                        <CardDescription>Write the function code that will be executed</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Textarea
                                id="code"
                                v-model="form.config.code"
                                placeholder="// Your function code here
async function execute(params) {
    return { result: 'success' };
}"
                                rows="10"
                                class="font-mono text-sm"
                                required
                            />
                        </div>
                    </CardContent>
                </Card>

                <!-- Command Configuration -->
                <Card v-if="form.type === 'command'">
                    <CardHeader>
                        <CardTitle>Command Configuration</CardTitle>
                        <CardDescription>Configure the shell command to execute</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Label for="command">Command</Label>
                            <Input
                                id="command"
                                v-model="form.config.command"
                                placeholder="echo 'Hello World'"
                                required
                            />
                            <p class="text-sm text-muted-foreground">
                                The command will be executed in a sandboxed environment.
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-4">
                    <Button variant="outline" type="button" as-child>
                        <Link href="/tools">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="form.processing">
                        {{ form.processing ? 'Creating...' : 'Create Tool' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
