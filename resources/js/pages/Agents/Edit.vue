<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted } from 'vue';
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
import { ArrowLeft, ChevronUp, ChevronDown, X } from 'lucide-vue-next';
import { update } from '@/actions/App/Http/Controllers/Api/V1/AgentController';
import { models as fetchBackendModels } from '@/actions/App/Http/Controllers/Api/V1/AIBackendController';
import type { Agent, SystemPrompt, ModelConfig } from '@/types';

const props = defineProps<{
    agent: Agent & { system_prompts?: SystemPrompt[] };
    systemPrompts: SystemPrompt[];
    backends: string[];
}>();

const form = ref({
    name: props.agent.name,
    description: props.agent.description || '',
    code: props.agent.code,
    config: props.agent.config,
    model_config: {
        model: props.agent.model_config?.model,
        temperature: props.agent.model_config?.temperature,
        max_tokens: props.agent.model_config?.max_tokens,
        top_p: props.agent.model_config?.top_p,
        top_k: props.agent.model_config?.top_k,
        context_length: props.agent.model_config?.context_length,
        timeout: props.agent.model_config?.timeout,
    } as ModelConfig,
    status: props.agent.status as 'active' | 'inactive' | 'error',
    ai_backend: props.agent.ai_backend,
    system_prompt_ids: props.agent.system_prompts
        ?.sort((a, b) => (a.pivot?.order ?? 0) - (b.pivot?.order ?? 0))
        .map(p => p.id) || [],
});

const availableModels = ref<string[]>([]);
const loadingModels = ref(false);

const loadModelsForBackend = async (backend: string) => {
    loadingModels.value = true;
    try {
        const response = await fetch(fetchBackendModels.url(backend));
        if (response.ok) {
            const data = await response.json();
            availableModels.value = data.models?.map((m: { name: string }) => m.name) || [];
        } else {
            availableModels.value = [];
        }
    } catch {
        availableModels.value = [];
    } finally {
        loadingModels.value = false;
    }
};

watch(() => form.value.ai_backend, (newBackend) => {
    form.value.model_config.model = undefined;
    loadModelsForBackend(newBackend);
});

onMounted(() => {
    loadModelsForBackend(form.value.ai_backend);
});

const errors = ref<Record<string, string>>({});
const processing = ref(false);

const submit = async () => {
    processing.value = true;
    errors.value = {};

    try {
        const response = await fetch(update.url(props.agent.id), {
            method: 'PUT',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify(form.value),
        });

        if (response.ok) {
            router.visit(`/agents/${props.agent.id}`);
        } else if (response.status === 422) {
            const data = await response.json();
            errors.value = data.errors || {};
        }
    } finally {
        processing.value = false;
    }
};

const availablePrompts = computed(() => {
    return props.systemPrompts.filter(p => !form.value.system_prompt_ids.includes(p.id));
});

const getPromptName = (id: number) => {
    return props.systemPrompts.find(p => p.id === id)?.name || 'Unknown';
};

const addPrompt = (id: string) => {
    const promptId = parseInt(id);
    if (!form.value.system_prompt_ids.includes(promptId)) {
        form.value.system_prompt_ids.push(promptId);
    }
};

const removePrompt = (index: number) => {
    form.value.system_prompt_ids.splice(index, 1);
};

const moveUp = (index: number) => {
    if (index > 0) {
        const temp = form.value.system_prompt_ids[index];
        form.value.system_prompt_ids[index] = form.value.system_prompt_ids[index - 1];
        form.value.system_prompt_ids[index - 1] = temp;
    }
};

const moveDown = (index: number) => {
    if (index < form.value.system_prompt_ids.length - 1) {
        const temp = form.value.system_prompt_ids[index];
        form.value.system_prompt_ids[index] = form.value.system_prompt_ids[index + 1];
        form.value.system_prompt_ids[index + 1] = temp;
    }
};
</script>

<template>
    <AppLayout :title="`Edit ${agent.name}`">
        <div class="space-y-4">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="`/agents/${agent.id}`">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-xl font-semibold">Edit Agent</h1>
                    <p class="text-sm text-muted-foreground">Update {{ agent.name }}</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Basic Information</CardTitle>
                        <CardDescription>Update the agent's identity and configuration</CardDescription>
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
                                <p v-if="errors.name" class="text-sm text-destructive">
                                    {{ errors.name }}
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
                                <p v-if="errors.ai_backend" class="text-sm text-destructive">
                                    {{ errors.ai_backend }}
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
                            <p v-if="errors.description" class="text-sm text-destructive">
                                {{ errors.description }}
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
                                    <SelectItem value="error">Error</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Model Configuration</CardTitle>
                        <CardDescription>Customize AI parameters (leave empty for defaults)</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="model">Model</Label>
                                <Select v-model="form.model_config.model">
                                    <SelectTrigger>
                                        <SelectValue placeholder="Use backend default" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem
                                            v-for="model in availableModels"
                                            :key="model"
                                            :value="model"
                                        >
                                            {{ model }}
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                <p v-if="loadingModels" class="text-xs text-muted-foreground">
                                    Loading models...
                                </p>
                                <p v-else-if="!availableModels.length" class="text-xs text-muted-foreground">
                                    No models available for this backend
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="temperature">Temperature</Label>
                                <Input
                                    id="temperature"
                                    type="number"
                                    v-model.number="form.model_config.temperature"
                                    placeholder="0.7"
                                    min="0"
                                    max="2"
                                    step="0.1"
                                />
                                <p class="text-xs text-muted-foreground">Controls randomness (0-2)</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="max_tokens">Max Tokens</Label>
                                <Input
                                    id="max_tokens"
                                    type="number"
                                    v-model.number="form.model_config.max_tokens"
                                    placeholder="4096"
                                    min="1"
                                />
                                <p class="text-xs text-muted-foreground">Maximum response length</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="context_length">Context Length</Label>
                                <Input
                                    id="context_length"
                                    type="number"
                                    v-model.number="form.model_config.context_length"
                                    placeholder="4096"
                                    min="1024"
                                />
                                <p class="text-xs text-muted-foreground">Context window size</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="top_p">Top P</Label>
                                <Input
                                    id="top_p"
                                    type="number"
                                    v-model.number="form.model_config.top_p"
                                    placeholder="1.0"
                                    min="0"
                                    max="1"
                                    step="0.05"
                                />
                                <p class="text-xs text-muted-foreground">Nucleus sampling (0-1)</p>
                            </div>

                            <div class="space-y-2">
                                <Label for="timeout">Timeout (seconds)</Label>
                                <Input
                                    id="timeout"
                                    type="number"
                                    v-model.number="form.model_config.timeout"
                                    placeholder="120"
                                    min="10"
                                    max="3600"
                                />
                                <p class="text-xs text-muted-foreground">Request timeout</p>
                            </div>
                        </div>
                    </CardContent>
                </Card>

                <Card v-if="systemPrompts.length > 0">
                    <CardHeader>
                        <CardTitle>System Prompts</CardTitle>
                        <CardDescription>Select and order prompts for this agent</CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-4">
                        <div v-if="form.system_prompt_ids.length" class="space-y-2">
                            <div
                                v-for="(promptId, index) in form.system_prompt_ids"
                                :key="promptId"
                                class="flex items-center gap-2 p-3 border rounded-lg bg-muted/50"
                            >
                                <span class="text-muted-foreground w-6 text-sm">{{ index + 1 }}.</span>
                                <span class="flex-1 font-medium">{{ getPromptName(promptId) }}</span>
                                <Button variant="ghost" size="icon" class="h-8 w-8" type="button" @click="moveUp(index)" :disabled="index === 0">
                                    <ChevronUp class="h-4 w-4" />
                                </Button>
                                <Button variant="ghost" size="icon" class="h-8 w-8" type="button" @click="moveDown(index)" :disabled="index === form.system_prompt_ids.length - 1">
                                    <ChevronDown class="h-4 w-4" />
                                </Button>
                                <Button variant="ghost" size="icon" class="h-8 w-8" type="button" @click="removePrompt(index)">
                                    <X class="h-4 w-4" />
                                </Button>
                            </div>
                        </div>

                        <Select v-if="availablePrompts.length" @update:model-value="addPrompt">
                            <SelectTrigger>
                                <SelectValue placeholder="Add system prompt..." />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="prompt in availablePrompts" :key="prompt.id" :value="prompt.id.toString()">
                                    {{ prompt.name }}
                                </SelectItem>
                            </SelectContent>
                        </Select>

                        <p v-if="!form.system_prompt_ids.length" class="text-sm text-muted-foreground">
                            No system prompts selected. Add prompts above or use the legacy code field below.
                        </p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Legacy System Prompt</CardTitle>
                        <CardDescription>Direct prompt code (used if no system prompts are selected)</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div class="space-y-2">
                            <Textarea
                                id="code"
                                v-model="form.code"
                                placeholder="You are a helpful assistant..."
                                rows="6"
                                class="font-mono text-sm"
                            />
                            <p v-if="errors.code" class="text-sm text-destructive">
                                {{ errors.code }}
                            </p>
                        </div>
                    </CardContent>
                </Card>

                <div class="flex justify-end gap-4">
                    <Button variant="outline" type="button" as-child>
                        <Link :href="`/agents/${agent.id}`">Cancel</Link>
                    </Button>
                    <Button type="submit" :disabled="processing">
                        {{ processing ? 'Saving...' : 'Save Changes' }}
                    </Button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
