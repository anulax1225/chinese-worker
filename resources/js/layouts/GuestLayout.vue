<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

defineProps<{
    title?: string;
}>();
</script>

<template>
    <div class="min-h-screen bg-gradient-to-br from-primary/10 via-background to-accent/10">
        <Head :title="title" />
        <slot />
    </div>
</template>
