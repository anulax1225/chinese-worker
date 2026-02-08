<script setup lang="ts">
import { computed } from 'vue';
import { Sparkles } from 'lucide-vue-next';

const props = defineProps<{
    agentName: string;
}>();

const emit = defineEmits<{
    selectPrompt: [prompt: string];
}>();

// Suggested prompts for empty state
const suggestedPrompts = computed(() => {
    const name = props.agentName.toLowerCase();

    // Agent-specific prompts based on name
    if (name.includes('code') || name.includes('dev')) {
        return [
            'Review my code for best practices',
            'Help me debug an issue',
            'Explain this code snippet',
        ];
    }

    if (name.includes('write') || name.includes('content')) {
        return [
            'Help me write a blog post',
            'Improve this paragraph',
            'Create an outline for...',
        ];
    }

    // Default prompts
    return [
        'What can you help me with?',
        'Explain your capabilities',
        'Help me get started',
    ];
});
</script>

<template>
    <div class="flex flex-1 justify-center items-center h-full">
        <div class="max-w-md text-center">
            <div class="flex justify-center items-center bg-primary/10 mx-auto mb-4 rounded-full w-16 h-16">
                <Sparkles class="w-8 h-8 text-primary" />
            </div>
            <h3 class="mb-2 font-medium text-lg">Start a conversation</h3>
            <p class="mb-6 text-muted-foreground text-sm">
                Send a message to {{ agentName }} to begin.
            </p>
            <!-- Suggested prompts -->
            <div class="flex flex-col gap-2">
                <button
                    v-for="prompt in suggestedPrompts"
                    :key="prompt"
                    type="button"
                    class="bg-card hover:bg-accent px-4 py-3 border border-border hover:border-accent rounded-lg text-sm text-left transition-colors"
                    @click="emit('selectPrompt', prompt)"
                >
                    {{ prompt }}
                </button>
            </div>
        </div>
    </div>
</template>
