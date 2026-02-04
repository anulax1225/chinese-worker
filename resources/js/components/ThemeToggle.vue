<script setup lang="ts">
import { Moon, Sun } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { useTheme } from '@/composables/useTheme';

const { resolvedTheme, toggleTheme } = useTheme();
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        class="h-9 w-9"
        @click="toggleTheme"
    >
        <Sun v-if="resolvedTheme === 'dark'" class="h-4 w-4" />
        <Moon v-else class="h-4 w-4" />
        <span class="sr-only">Toggle theme</span>
    </Button>
</template>
