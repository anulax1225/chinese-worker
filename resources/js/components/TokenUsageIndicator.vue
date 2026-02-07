<script setup lang="ts">
import { computed } from 'vue';
import { Progress } from '@/components/ui/progress';
import { Coins } from 'lucide-vue-next';
import type { TokenUsage } from '@/types';
import { cn } from '@/lib/utils';

const props = defineProps<{
    tokenUsage: TokenUsage;
    compact?: boolean;
}>();

const formatTokens = (count: number): string => {
    if (count >= 1000) {
        return `${(count / 1000).toFixed(1)}K`;
    }
    return count.toString();
};

const usageColor = computed(() => {
    const percentage = props.tokenUsage.usage_percentage;
    if (percentage === null) return 'text-muted-foreground';
    if (percentage >= 90) return 'text-destructive';
    if (percentage >= 75) return 'text-amber-500';
    return 'text-muted-foreground';
});

const progressColor = computed(() => {
    const percentage = props.tokenUsage.usage_percentage;
    if (percentage === null) return '';
    if (percentage >= 90) return '[&>[data-slot=progress-indicator]]:bg-destructive';
    if (percentage >= 75) return '[&>[data-slot=progress-indicator]]:bg-amber-500';
    return '';
});

const hasContextLimit = computed(() => props.tokenUsage.context_limit !== null);
</script>

<template>
    <div class="flex items-center gap-2">
        <!-- Compact mode: just icon and number -->
        <template v-if="compact">
            <div :class="cn('flex items-center gap-1 text-xs', usageColor)">
                <Coins class="w-3 h-3" />
                <span>{{ formatTokens(tokenUsage.total_tokens) }}</span>
                <template v-if="hasContextLimit && tokenUsage.usage_percentage !== null">
                    <span class="text-muted-foreground/50">/</span>
                    <span class="text-muted-foreground/70">{{ tokenUsage.usage_percentage }}%</span>
                </template>
            </div>
        </template>

        <!-- Full mode: with progress bar -->
        <template v-else>
            <div class="flex flex-col gap-1 min-w-24">
                <div :class="cn('flex items-center justify-between text-xs', usageColor)">
                    <div class="flex items-center gap-1">
                        <Coins class="w-3 h-3" />
                        <span>{{ formatTokens(tokenUsage.total_tokens) }}</span>
                    </div>
                    <span v-if="hasContextLimit" class="text-muted-foreground/70">
                        / {{ formatTokens(tokenUsage.context_limit!) }}
                    </span>
                </div>
                <Progress
                    v-if="hasContextLimit && tokenUsage.usage_percentage !== null"
                    :model-value="tokenUsage.usage_percentage"
                    :class="cn('h-1', progressColor)"
                />
            </div>
        </template>
    </div>
</template>
