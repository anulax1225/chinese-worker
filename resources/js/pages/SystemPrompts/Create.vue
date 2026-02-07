<script setup lang="ts">
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowLeft, Plus, X } from 'lucide-vue-next';
import { store } from '@/actions/App/Http/Controllers/Api/V1/SystemPromptController';

const form = ref({
    name: '',
    slug: '',
    template: '',
    required_variables: [] as string[],
    default_values: {} as Record<string, string>,
    is_active: true,
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);
const newVariable = ref('');

const generateSlug = () => {
    form.value.slug = form.value.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');
};

const addVariable = () => {
    const variable = newVariable.value.trim();
    if (variable && !form.value.required_variables.includes(variable)) {
        form.value.required_variables.push(variable);
        form.value.default_values[variable] = '';
    }
    newVariable.value = '';
};

const removeVariable = (variable: string) => {
    form.value.required_variables = form.value.required_variables.filter(v => v !== variable);
    delete form.value.default_values[variable];
};

const submit = async () => {
    processing.value = true;
    errors.value = {};

    try {
        const response = await fetch(store.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify({
                ...form.value,
                required_variables: form.value.required_variables.length ? form.value.required_variables : null,
                default_values: Object.keys(form.value.default_values).length ? form.value.default_values : null,
            }),
        });

        if (response.ok) {
            const data = await response.json();
            router.visit(`/system-prompts/${data.id}`);
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
    <AppLayout title="Create System Prompt">
        <div class="max-w-3xl mx-auto space-y-6">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link href="/system-prompts">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-3xl font-bold">Create System Prompt</h1>
                    <p class="text-muted-foreground">Define a reusable prompt template</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-6">
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Set up the prompt's identity</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="name">Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    placeholder="Greeting Prompt"
                                    @blur="!form.slug && generateSlug()"
                                    required
                                />
                                <p v-if="errors.name" class="text-sm text-destructive">
                                    {{ errors.name }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="slug">Slug</Label>
                                <Input
                                    id="slug"
                                    v-model="form.slug"
                                    placeholder="greeting-prompt"
                                    class="font-mono"
                                    required
                                />
                                <p v-if="errors.slug" class="text-sm text-destructive">
                                    {{ errors.slug }}
                                </p>
                            </div>
                        </div>

                        <div class="flex items-center gap-3">
                            <Switch
                                id="is_active"
                                v-model:checked="form.is_active"
                            />
                            <Label for="is_active">Active</Label>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Template</CardTitle>
                        <CardDescription>
                            Write the prompt using Blade syntax. Use &#123;&#123; $variable &#125;&#125; for variables.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Textarea
                                id="template"
                                v-model="form.template"
                                placeholder="You are {{ $agent_name }}, a helpful assistant.

@if($include_greeting)
Always greet the user warmly.
@endif"
                                rows="10"
                                class="font-mono text-sm"
                                required
                            />
                            <p v-if="errors.template" class="text-sm text-destructive">
                                {{ errors.template }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Variables</CardTitle>
                        <CardDescription>
                            Define required variables and their default values
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="flex gap-2">
                            <Input
                                v-model="newVariable"
                                placeholder="variable_name"
                                class="font-mono"
                                @keyup.enter.prevent="addVariable"
                            />
                            <Button type="button" variant="outline" @click="addVariable">
                                <Plus class="h-4 w-4" />
                            </Button>
                        </div>

                        <div v-if="form.required_variables.length" class="space-y-3">
                            <div
                                v-for="variable in form.required_variables"
                                :key="variable"
                                class="flex items-center gap-3 p-3 bg-muted/50 rounded-lg"
                            >
                                <code class="text-sm font-mono flex-shrink-0">{{ variable }}</code>
                                <Input
                                    v-model="form.default_values[variable]"
                                    placeholder="Default value (optional)"
                                    class="flex-1"
                                />
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    @click="removeVariable(variable)"
                                >
                                    <X class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>

                        <p v-else class="text-sm text-muted-foreground">
                            No required variables defined. Built-in variables like agent_name and agent_description are always available.
                        </p>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-4">
                    <Button variant="outline" type="button" as-child>
                        <Link href="/system-prompts">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Creating...' : 'Create Prompt' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
