<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Cpu,
    CheckCircle2,
    XCircle,
    HelpCircle,
    Zap,
    MessageSquare,
    Eye,
    Database,
    Star,
} from 'lucide-vue-next';
import type { AIBackend } from '@/types';

defineProps<{
    backends: AIBackend[];
    defaultBackend: string;
}>();

const getStatusIcon = (status: AIBackend['status']) => {
    const icons = {
        connected: CheckCircle2,
        error: XCircle,
        unknown: HelpCircle,
    };
    return icons[status] || HelpCircle;
};

const getStatusClass = (status: AIBackend['status']) => {
    const classes = {
        connected: 'text-green-500',
        error: 'text-red-500',
        unknown: 'text-muted-foreground',
    };
    return classes[status] || 'text-muted-foreground';
};

const getDriverBadgeClass = (driver: string) => {
    const colors: Record<string, string> = {
        ollama: 'bg-purple-500/10 text-purple-600 border-purple-500/20',
        anthropic: 'bg-orange-500/10 text-orange-600 border-orange-500/20',
        openai: 'bg-green-500/10 text-green-600 border-green-500/20',
    };
    return colors[driver] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};
</script>

<template>
    <AppLayout title="AI Backends">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-semibold">AI Backends</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Manage your AI backend connections and models
                </p>
            </div>

            <!-- Empty State -->
            <div
                v-if="backends.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <Cpu class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No backends configured</h3>
                <p class="text-muted-foreground">
                    Configure AI backends in your environment settings.
                </p>
            </div>

            <!-- Backend Cards -->
            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="backend in backends"
                    :key="backend.name"
                    :href="`/ai-backends/${backend.name}`"
                    class="group"
                >
                    <Card class="h-full hover:border-primary/50 hover:shadow-md transition-all">
                        <CardHeader class="pb-3">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-primary/10">
                                        <Cpu class="h-4 w-4 text-primary" />
                                    </div>
                                    <div>
                                        <CardTitle class="text-base flex items-center gap-2">
                                            {{ backend.name }}
                                            <Star
                                                v-if="backend.is_default"
                                                class="h-3.5 w-3.5 text-yellow-500 fill-yellow-500"
                                            />
                                        </CardTitle>
                                        <Badge
                                            variant="outline"
                                            :class="['text-xs font-normal mt-1', getDriverBadgeClass(backend.driver)]"
                                        >
                                            {{ backend.driver }}
                                        </Badge>
                                    </div>
                                </div>
                                <component
                                    :is="getStatusIcon(backend.status)"
                                    :class="['h-5 w-5', getStatusClass(backend.status)]"
                                />
                            </div>
                        </CardHeader>
                        <CardContent>
                            <!-- Model info -->
                            <div v-if="backend.model" class="text-sm text-muted-foreground mb-3">
                                <span class="font-medium text-foreground">Model:</span>
                                {{ backend.model }}
                            </div>

                            <!-- Error message -->
                            <div v-if="backend.error" class="text-sm text-red-500 mb-3 truncate">
                                {{ backend.error }}
                            </div>

                            <!-- Capabilities -->
                            <div class="flex flex-wrap gap-2">
                                <Badge
                                    v-if="backend.capabilities.streaming"
                                    variant="secondary"
                                    class="text-xs gap-1"
                                >
                                    <Zap class="h-3 w-3" />
                                    Streaming
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.function_calling"
                                    variant="secondary"
                                    class="text-xs gap-1"
                                >
                                    <MessageSquare class="h-3 w-3" />
                                    Tools
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.vision"
                                    variant="secondary"
                                    class="text-xs gap-1"
                                >
                                    <Eye class="h-3 w-3" />
                                    Vision
                                </Badge>
                                <Badge
                                    v-if="backend.models.length > 0"
                                    variant="secondary"
                                    class="text-xs gap-1"
                                >
                                    <Database class="h-3 w-3" />
                                    {{ backend.models.length }} models
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
