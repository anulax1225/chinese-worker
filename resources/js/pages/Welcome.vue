<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { GuestLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Github, BookOpen, Bot, Wrench, MessageSquare, Zap } from 'lucide-vue-next';
import AppBrand from '@/components/AppBrand.vue';

const features = [
    {
        icon: Bot,
        title: 'AI Agents',
        description: 'Create and manage intelligent agents powered by various AI backends.',
    },
    {
        icon: Wrench,
        title: 'Custom Tools',
        description: 'Build custom tools that extend your agents capabilities.',
    },
    {
        icon: MessageSquare,
        title: 'Conversations',
        description: 'Real-time conversations with streaming responses.',
    },
    {
        icon: Zap,
        title: 'Multi-Platform',
        description: 'Access from web, CLI, or integrate via API.',
    },
];
</script>

<template>
    <GuestLayout title="Welcome">
        <!-- Navbar -->
        <nav class="flex items-center justify-between px-6 py-4 lg:px-8">
            <AppBrand size="lg" />
            <div class="flex items-center gap-4">
                <a
                    href="https://github.com"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-muted-foreground hover:text-foreground transition-colors"
                >
                    <Github class="h-5 w-5" />
                </a>
                <a
                    href="/docs"
                    class="text-muted-foreground hover:text-foreground transition-colors flex items-center gap-1"
                >
                    <BookOpen class="h-5 w-5" />
                    <span class="hidden sm:inline">Docs</span>
                </a>
                <Button variant="ghost" as-child>
                    <Link href="/login">Sign In</Link>
                </Button>
            </div>
        </nav>

        <!-- Hero Section -->
        <div class="flex flex-col items-center justify-center min-h-[80vh] px-6 text-center">
            <div class="max-w-3xl mx-auto">
                <h1 class="text-4xl font-bold tracking-tight sm:text-6xl bg-gradient-to-r from-primary to-primary/60 bg-clip-text text-transparent">
                    Chinese Worker
                </h1>
                <p class="mt-2 text-xl text-muted-foreground">AI Agent Platform</p>
                <p class="mt-6 text-lg leading-8 text-muted-foreground">
                    Build, manage, and orchestrate AI agents with custom tools.
                    Connect via web, CLI, or API for seamless integration.
                </p>
                <div class="mt-10 flex items-center justify-center gap-4">
                    <Button size="lg" as-child>
                        <Link href="/register">Get Started</Link>
                    </Button>
                    <Button size="lg" variant="outline" as-child>
                        <Link href="/login">Sign In</Link>
                    </Button>
                </div>
            </div>
        </div>

        <!-- Features Section -->
        <div class="px-6 py-24 lg:px-8">
            <div class="mx-auto max-w-4xl">
                <h2 class="text-2xl font-bold text-center mb-12">Features</h2>
                <div class="grid grid-cols-1 gap-8 sm:grid-cols-2">
                    <div
                        v-for="feature in features"
                        :key="feature.title"
                        class="relative rounded-lg border bg-card p-6 shadow-sm"
                    >
                        <div class="flex items-center gap-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10">
                                <component :is="feature.icon" class="h-5 w-5 text-primary" />
                            </div>
                            <h3 class="text-lg font-semibold">{{ feature.title }}</h3>
                        </div>
                        <p class="mt-4 text-muted-foreground">{{ feature.description }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <footer class="border-t">
            <div class="px-6 py-8 text-center text-sm text-muted-foreground">
                &copy; {{ new Date().getFullYear() }} Chinese Worker. All rights reserved.
            </div>
        </footer>
    </GuestLayout>
</template>
