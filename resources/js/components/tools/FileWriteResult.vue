<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { FileEdit, CheckCircle } from 'lucide-vue-next';

const props = defineProps<{
    content: string;
    toolName?: string;
}>();
</script>

<template>
    <div class="flex gap-3">
        <div class="h-8 w-8 shrink-0 rounded-full bg-purple-500/10 flex items-center justify-center">
            <FileEdit class="h-4 w-4 text-purple-500" />
        </div>
        <Card class="flex-1 max-w-[85%] p-3 border-purple-500/30 bg-purple-500/5">
            <!-- Header -->
            <div class="flex items-center gap-2 mb-2">
                <Badge variant="outline" class="bg-purple-500/10 text-purple-600 border-purple-500/30 text-xs">
                    write
                </Badge>
                <span class="text-xs text-muted-foreground">File Write</span>
            </div>

            <!-- Success message -->
            <div class="flex items-center gap-2 p-2 bg-green-500/10 rounded-lg border border-green-500/20">
                <CheckCircle class="h-4 w-4 text-green-500 shrink-0" />
                <p class="text-sm text-green-700 dark:text-green-400">
                    {{ content || 'File written successfully' }}
                </p>
            </div>
        </Card>
    </div>
</template>
