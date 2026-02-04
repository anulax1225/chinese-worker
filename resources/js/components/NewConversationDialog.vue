<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
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
import type { Agent } from '@/types';

const props = defineProps<{
    agents: Pick<Agent, 'id' | 'name' | 'description'>[];
}>();

const open = defineModel<boolean>('open', { default: false });

const form = useForm({
    agent_id: '',
});

const submit = () => {
    form.post('/conversations', {
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
};

const handleOpenChange = (value: boolean) => {
    open.value = value;
    if (!value) {
        form.reset();
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="handleOpenChange">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <MessageSquarePlus class="h-5 w-5" />
                    New Conversation
                </DialogTitle>
                <DialogDescription>
                    Select an agent to start a new conversation.
                </DialogDescription>
            </DialogHeader>

            <form @submit.prevent="submit" class="space-y-4 py-4">
                <div class="space-y-2">
                    <Label for="agent">Agent</Label>
                    <Select v-model="form.agent_id" required>
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
                                    <Bot class="h-4 w-4 text-muted-foreground" />
                                    <div class="flex flex-col">
                                        <span>{{ agent.name }}</span>
                                        <span
                                            v-if="agent.description"
                                            class="text-xs text-muted-foreground truncate max-w-[200px]"
                                        >
                                            {{ agent.description }}
                                        </span>
                                    </div>
                                </div>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <p v-if="form.errors.agent_id" class="text-sm text-destructive">
                        {{ form.errors.agent_id }}
                    </p>
                    <p v-if="agents.length === 0" class="text-sm text-muted-foreground">
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
                        :disabled="form.processing || !form.agent_id || agents.length === 0"
                    >
                        {{ form.processing ? 'Starting...' : 'Start Conversation' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
