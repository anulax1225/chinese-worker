<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { ListTodo } from 'lucide-vue-next';

defineProps<{
    content: string;
    toolName?: string;
}>();
</script>

<template>
    <div class="flex gap-3">
        <div class="h-8 w-8 shrink-0 rounded-full bg-teal-500/10 flex items-center justify-center">
            <ListTodo class="h-4 w-4 text-teal-500" />
        </div>
        <Card class="flex-1 max-w-[85%] p-3 border-teal-500/30 bg-teal-500/5">
            <!-- Header -->
            <div class="flex items-center gap-2 mb-2">
                <Badge variant="outline" class="bg-teal-500/10 text-teal-600 border-teal-500/30 text-xs">
                    {{ toolName || 'todo' }}
                </Badge>
                <span class="text-xs text-muted-foreground">Todo</span>
            </div>

            <!-- Content -->
            <div class="text-sm whitespace-pre-wrap">{{ content }}</div>
        </Card>
    </div>
</template>
