<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { Menu, ChevronDown, LogOut, Settings } from 'lucide-vue-next';
import type { AppPageProps } from '@/types';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import AppBrand from '@/components/AppBrand.vue';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import ThemeToggle from '@/components/ThemeToggle.vue';

defineEmits<{
    openMobileMenu: [];
}>();

const page = usePage<AppPageProps>();

const user = computed(() => page.props.auth?.user);

const userInitials = computed(() => {
    if (!user.value?.name) return '?';
    return user.value.name
        .split(' ')
        .map((n: string) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
});

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <header class="fixed top-0 left-0 right-0 z-50 h-14 border-b border-border bg-background/80 backdrop-blur-sm">
        <div class="flex h-full items-center justify-between px-4">
            <!-- Left: Mobile menu + Logo + Breadcrumbs -->
            <div class="flex items-center gap-4">
                <!-- Mobile menu button -->
                <Button
                    variant="ghost"
                    size="icon"
                    class="lg:hidden h-9 w-9"
                    @click="$emit('openMobileMenu')"
                >
                    <Menu class="h-5 w-5" />
                    <span class="sr-only">Open menu</span>
                </Button>

                <!-- Logo -->
                <Link href="/dashboard" class="flex items-center">
                    <AppBrand size="sm" />
                </Link>

                <!-- Breadcrumbs (hidden on mobile) -->
                <Breadcrumbs class="hidden md:flex" />
            </div>

            <!-- Right: Theme Toggle + User Menu -->
            <div class="flex items-center gap-2">
                <ThemeToggle />

                <!-- User Dropdown -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" class="flex items-center gap-2 px-2">
                            <Avatar class="h-8 w-8">
                                <AvatarImage :src="user?.avatar" />
                                <AvatarFallback>{{ userInitials }}</AvatarFallback>
                            </Avatar>
                            <span class="hidden sm:inline text-sm font-medium">
                                {{ user?.name }}
                            </span>
                            <ChevronDown class="h-4 w-4 text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuLabel>My Account</DropdownMenuLabel>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child>
                            <Link href="/settings" class="cursor-pointer">
                                <Settings class="mr-2 h-4 w-4" />
                                Settings
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @click="logout" class="cursor-pointer text-destructive">
                            <LogOut class="mr-2 h-4 w-4" />
                            Log out
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
