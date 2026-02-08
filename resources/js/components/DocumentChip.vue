<script setup lang="ts">
import { X, FileText } from 'lucide-vue-next';
import type { Document } from '@/types';

defineProps<{
    document: Document;
}>();

defineEmits<{
    remove: [];
}>();
</script>

<template>
    <div class="inline-flex items-center gap-1.5 bg-muted px-2 py-1 rounded-md text-xs">
        <FileText class="w-3 h-3 text-muted-foreground shrink-0" />
        <span class="max-w-32 truncate">{{ document.title || 'Untitled' }}</span>
        <button
            type="button"
            class="hover:bg-muted-foreground/20 rounded p-0.5 transition-colors"
            @click="$emit('remove')"
        >
            <X class="w-3 h-3" />
        </button>
    </div>
</template>
