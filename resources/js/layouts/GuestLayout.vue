<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

interface Props {
    title?: string;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Chinese Worker',
});
</script>

<template>
    <div class="min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-background">
        <Head :title="title" />

        <div class="w-full sm:max-w-md px-6 py-4">
            <div class="mb-6 flex justify-center">
                <h1 class="text-3xl font-bold tracking-tight text-foreground">
                    Chinese Worker
                </h1>
            </div>

            <slot />
        </div>
    </div>
</template>
