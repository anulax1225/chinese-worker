<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppBrand from '@/components/AppBrand.vue';

defineProps<{
    title?: string;
}>();
</script>

<template>
    <div class="min-h-screen flex flex-col bg-gradient-to-br from-primary/10 via-background to-accent/10">
        <Head :title="title" />

        <!-- Header -->
        <header class="p-6">
            <Link href="/" class="hover:opacity-80 transition-opacity">
                <AppBrand />
            </Link>
        </header>

        <!-- Main Content -->
        <main class="flex-1 flex items-center justify-center p-6">
            <div class="w-full max-w-md">
                <slot />
            </div>
        </main>

        <!-- Footer -->
        <footer class="p-6 text-center text-sm text-muted-foreground">
            &copy; {{ new Date().getFullYear() }} Chinese Worker. All rights reserved.
        </footer>
    </div>
</template>
