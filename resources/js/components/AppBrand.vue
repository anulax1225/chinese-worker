<script setup lang="ts">
import AppLogo from './AppLogo.vue';

withDefaults(defineProps<{
    showText?: boolean;
    size?: 'sm' | 'md' | 'lg';
}>(), {
    showText: true,
    size: 'lg',
});

const sizeClasses = {
    sm: { logo: 'h-10 w-10', text: 'text-base' },
    md: { logo: 'h-12 w-12', text: 'text-xl' },
    lg: { logo: 'h-16 w-16', text: 'text-2xl' },
};
</script>

<template>
    <div class="flex items-center gap-1">
        <AppLogo :class="sizeClasses[size].logo" />
        <span v-if="showText" :class="['font-bold', sizeClasses[size].text]">
            Chinese Worker
        </span>
    </div>
</template>
