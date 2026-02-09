<script setup lang="ts">
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Bot } from 'lucide-vue-next';
</script>

<template>
    <div class="flex gap-3">
        <Avatar class="w-8 h-8 shrink-0">
            <AvatarFallback class="bg-secondary text-secondary-foreground">
                <Bot class="w-4 h-4" />
            </AvatarFallback>
        </Avatar>
        <div class="bg-card shadow-sm px-4 py-3 border-primary/30 border rounded-2xl rounded-tl-md">
            <div class="flex items-center gap-2">
                <div class="flex gap-1">
                    <div class="bg-primary/40 rounded-full w-2 h-2 animate-bounce" style="animation-delay: 0ms" />
                    <div class="bg-primary/40 rounded-full w-2 h-2 animate-bounce" style="animation-delay: 150ms" />
                    <div class="bg-primary/40 rounded-full w-2 h-2 animate-bounce" style="animation-delay: 300ms" />
                </div>
                <span class="text-muted-foreground text-xs">Thinking...</span>
            </div>
        </div>
    </div>
</template>
