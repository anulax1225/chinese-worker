<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Home, Bot, Wrench, FolderOpen, PlayCircle, Settings, User, LogOut } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import type { Auth } from '@/types/auth';

interface Props {
    title?: string;
    auth: Auth;
}

const props = withDefaults(defineProps<Props>(), {
    title: 'Dashboard',
});

const navigation = [
    { name: 'Dashboard', href: '/dashboard', icon: Home },
    { name: 'Agents', href: '/agents', icon: Bot },
    { name: 'Tools', href: '/tools', icon: Wrench },
    { name: 'Files', href: '/files', icon: FolderOpen },
    { name: 'Executions', href: '/executions', icon: PlayCircle },
    { name: 'AI Backends', href: '/ai-backends', icon: Settings },
];

const userInitials = computed(() => {
    if (!props.auth.user) return '?';
    return props.auth.user.name
        .split(' ')
        .map(n => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
});

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <div class="min-h-screen bg-background">
        <Head :title="title" />

        <!-- Sidebar -->
        <aside class="fixed inset-y-0 left-0 z-10 hidden w-64 flex-col border-r bg-card sm:flex">
            <div class="flex h-16 items-center border-b px-6">
                <h1 class="text-xl font-bold text-foreground">Chinese Worker</h1>
            </div>

            <nav class="flex-1 space-y-1 px-3 py-4">
                <Link
                    v-for="item in navigation"
                    :key="item.name"
                    :href="item.href"
                    class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                    :class="[
                        $page.url.startsWith(item.href)
                            ? 'bg-accent text-accent-foreground'
                            : 'text-muted-foreground',
                    ]"
                >
                    <component :is="item.icon" class="h-5 w-5" />
                    {{ item.name }}
                </Link>
            </nav>

            <!-- User Menu -->
            <div class="border-t p-4">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" class="w-full justify-start gap-3">
                            <Avatar class="h-8 w-8">
                                <AvatarFallback>{{ userInitials }}</AvatarFallback>
                            </Avatar>
                            <div class="flex flex-col items-start text-sm">
                                <span class="font-medium">{{ auth.user?.name }}</span>
                                <span class="text-xs text-muted-foreground">{{ auth.user?.email }}</span>
                            </div>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent class="w-56" align="end">
                        <DropdownMenuLabel>My Account</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @click="logout">
                            <LogOut class="mr-2 h-4 w-4" />
                            <span>Log out</span>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </aside>

        <!-- Main Content -->
        <div class="sm:pl-64">
            <main class="p-6">
                <slot />
            </main>
        </div>
    </div>
</template>
