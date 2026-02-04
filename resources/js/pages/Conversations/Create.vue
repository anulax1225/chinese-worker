<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
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
import { ArrowLeft, Send } from 'lucide-vue-next';
import type { Agent } from '@/types';

const props = defineProps<{
    agents: Pick<Agent, 'id' | 'name' | 'description'>[];
}>();

const form = useForm({
    agent_id: '',
    message: '',
});

const submit = () => {
    form.post('/conversations');
};
</script>

<template>
    <AppLayout title="New Conversation">
        <div class="space-y-4">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="icon" as-child>
                    <Link href="/conversations">
                        <ArrowLeft class="h-4 w-4" />
                    </Link>
                </Button>
                <div>
                    <h1 class="text-xl font-semibold">New Conversation</h1>
                    <p class="text-sm text-muted-foreground">Start a conversation with an agent</p>
                </div>
            </div>

            <Card class="max-w-2xl">
                <CardHeader>
                    <CardTitle>Start Conversation</CardTitle>
                    <CardDescription>Select an agent and send your first message</CardDescription>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="space-y-2">
                            <Label for="agent">Agent</Label>
                            <Select v-model="form.agent_id" required>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select an agent" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="agent in agents"
                                        :key="agent.id"
                                        :value="String(agent.id)"
                                    >
                                        <div>
                                            <p>{{ agent.name }}</p>
                                            <p v-if="agent.description" class="text-xs text-muted-foreground">
                                                {{ agent.description }}
                                            </p>
                                        </div>
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <p v-if="form.errors.agent_id" class="text-sm text-destructive">
                                {{ form.errors.agent_id }}
                            </p>
                            <p v-if="agents.length === 0" class="text-sm text-muted-foreground">
                                No active agents available.
                                <Link href="/agents/create" class="text-primary hover:underline">
                                    Create one first
                                </Link>
                            </p>
                        </div>

                        <div class="space-y-2">
                            <Label for="message">Message</Label>
                            <Textarea
                                id="message"
                                v-model="form.message"
                                placeholder="Type your message here..."
                                rows="4"
                                required
                            />
                            <p v-if="form.errors.message" class="text-sm text-destructive">
                                {{ form.errors.message }}
                            </p>
                        </div>

                        <div class="flex justify-end gap-4">
                            <Button variant="outline" type="button" as-child>
                                <Link href="/conversations">Cancel</Link>
                            </Button>
                            <Button
                                type="submit"
                                :disabled="form.processing || !form.agent_id || !form.message"
                            >
                                <Send class="h-4 w-4 mr-2" />
                                {{ form.processing ? 'Starting...' : 'Start Conversation' }}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
