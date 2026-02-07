<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { MessageSquarePlus, Bot } from 'lucide-vue-next';
import { store } from '@/actions/App/Http/Controllers/Api/V1/ConversationController';
import type { Agent } from '@/types';

const props = defineProps<{
    agents: Pick<Agent, 'id' | 'name' | 'description'>[];
}>();

const open = defineModel<boolean>('open', { default: false });

const agentId = ref('');
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const submit = async () => {
    if (!agentId.value) return;

    processing.value = true;
    errors.value = {};

    try {
        const response = await fetch(store.url(agentId.value), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify({
                client_type: "cli_web", 
            }),
        });

        if (response.ok) {
            const data = await response.json();
            open.value = false;
            agentId.value = '';
            router.visit(`/conversations/${data.id}`);
        } else if (response.status === 422) {
            const data = await response.json();
            errors.value = data.errors || {};
        }
    } finally {
        processing.value = false;
    }
};

const handleOpenChange = (value: boolean) => {
    open.value = value;
    if (!value) {
        agentId.value = '';
        errors.value = {};
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <MessageSquarePlus class="w-5 h-5" />
                    New Conversation
                </DialogTitle>
                <DialogDescription>
                    Select an agent to start a new conversation.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submit" class="space-y-4 py-4">
                <div class="space-y-2">
                    <Label for="agent">Agent</Label>
                    <Select v-model="agentId" required>
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select an agent" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="agent in agents"
                                :key="agent.id"
                                :value="String(agent.id)"
                            >
                                <div class="flex items-center gap-2">
                                    <Bot class="w-4 h-4 text-muted-foreground" />
                                    <div class="flex flex-col">
                                        <span>{{ agent.name }}</span>
                                        <span
                                            v-if="agent.description"
                                            class="max-w-[200px] text-muted-foreground text-xs truncate"
                                        >
                                            {{ agent.description }}
                                        </span>
                                    </div>
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="errors.agent_id" class="text-destructive text-sm">
                        {{ errors.agent_id }}
                    </p>
                    <p v-if="agents.length === 0" class="text-muted-foreground text-sm">
                        No active agents available. Create one first.
                    </p>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        :disabled="processing || !agentId || agents.length === 0"
                    >
                        {{ processing ? 'Starting...' : 'Start Conversation' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
