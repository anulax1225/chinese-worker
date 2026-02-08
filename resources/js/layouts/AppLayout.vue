<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import { toast } from 'vue-sonner';
import type { AppPageProps, Agent } from '@/types';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Sonner } from '@/components/ui/sonner';
import {
    Home,
    Bot,
    Wrench,
    MessageSquare,
    FileText,
    Search,
    X,
    Plus,
    ChevronDown,
    ChevronRight,
    PanelLeftClose,
    PanelLeft,
    Circle,
    FileCog,
    Cpu,
    Library,
} from 'lucide-vue-next';
import AppHeader from '@/components/AppHeader.vue';
import NewConversationDialog from '@/components/NewConversationDialog.vue';
import { useSidebar } from '@/composables/useSidebar';

defineProps<{
    title?: string;
}>();

const page = usePage<AppPageProps>();
const mobileMenuOpen = ref(false);
const newConversationDialogOpen = ref(false);

const { sidebarCollapsed, adminExpanded, toggleSidebar, toggleAdmin } = useSidebar();

const agents = computed(() => (page.props.agents || []) as Pick<Agent, 'id' | 'name' | 'description'>[]);

const adminNavigation = [
    { name: 'Dashboard', href: '/dashboard', icon: Home },
    { name: 'Agents', href: '/agents', icon: Bot },
    { name: 'System Prompts', href: '/system-prompts', icon: FileCog },
    { name: 'Tools', href: '/tools', icon: Wrench },
    { name: 'AI Backends', href: '/ai-backends', icon: Cpu },
    { name: 'Documents', href: '/documents', icon: Library },
    { name: 'Files', href: '/files', icon: FileText },
    { name: 'Search', href: '/search', icon: Search },
];

const sidebarConversations = computed(() => page.props.sidebarConversations || []);

const isCurrentRoute = (href: string) => {
    const currentPath = window.location.pathname;
    if (href === '/dashboard') {
        return currentPath === '/dashboard';
    }
    return currentPath.startsWith(href);
};

const isCurrentConversation = (id: number) => {
    const currentPath = window.location.pathname;
    return currentPath === `/conversations/${id}`;
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'text-blue-500',
        completed: 'text-green-500',
        failed: 'text-red-500',
        cancelled: 'text-muted-foreground',
        waiting_tool: 'text-amber-500',
    };
    return colors[status] || 'text-muted-foreground';
};

// Watch for flash messages
watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) {
            toast.success(flash.success);
        }
        if (flash?.error) {
            toast.error(flash.error);
        }
    },
    { immediate: true }
);
</script>

<template>
    <div class="bg-background min-h-screen">
        <Head :title="title" />
        <Sonner />

        <!-- Header -->
        <AppHeader @open-mobile-menu="mobileMenuOpen = true" />

        <!-- Mobile sidebar backdrop -->
        <div
            v-if="mobileMenuOpen"
            class="lg:hidden z-40 fixed inset-0 bg-black/50"
            @click="mobileMenuOpen = false"
        />

        <!-- Sidebar -->
        <aside
            :class="[
                'fixed left-0 z-50 bg-card border-r transform transition-all duration-200 ease-in-out',
                'top-14 h-[calc(100vh-3.5rem)]',
                sidebarCollapsed ? 'w-16' : 'w-64',
                mobileMenuOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            ]"
        >
            <div class="flex flex-col h-full">
                <!-- Mobile close button -->
                <div class="lg:hidden flex justify-end items-center px-4 h-12">
                    <Button
                        variant="ghost"
                        size="icon"
                        @click="mobileMenuOpen = false"
                    >
                        <X class="w-5 h-5" />
                    </Button>
                </div>

                <!-- Sidebar content -->
                <nav class="flex-1 space-y-2 p-3 overflow-y-auto">
                    <!-- New Conversation Button -->
                    <Button
                        :class="[
                            'flex items-center gap-2 w-full rounded-md font-medium transition-colors',
                            sidebarCollapsed
                                ? 'justify-center p-2'
                                : 'px-3 py-2 text-sm',
                        ]"
                        :title="sidebarCollapsed ? 'New Conversation' : undefined"
                        @click="newConversationDialogOpen = true; mobileMenuOpen = false"
                    >
                        <Plus class="w-4 h-4" />
                        <span v-if="!sidebarCollapsed">New Conversation</span>
                    </Button>

                    <Separator class="my-3" />

                    <!-- Latest Chats Section -->
                    <div v-if="sidebarConversations.length > 0 && !sidebarCollapsed">
                        <p class="mb-2 px-3 font-semibold text-muted-foreground text-xs uppercase tracking-wider">
                            Latest Chats
                        </p>
                        <div class="space-y-1">
                            <Link
                                v-for="conversation in sidebarConversations"
                                :key="conversation.id"
                                :href="`/conversations/${conversation.id}`"
                                :class="[
                                    'flex items-center gap-2 px-3 py-2 text-sm rounded-md transition-colors',
                                    isCurrentConversation(conversation.id)
                                        ? 'bg-muted text-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                ]"
                                @click="mobileMenuOpen = false"
                            >
                                <Circle :class="['h-2 w-2 fill-current', getStatusColor(conversation.status)]" />
                                <span class="flex-1 truncate">{{ conversation.title }}</span>
                            </Link>
                            <Link
                                href="/conversations"
                                class="flex items-center gap-2 px-3 py-1.5 text-muted-foreground hover:text-foreground text-xs transition-colors"
                                @click="mobileMenuOpen = false"
                            >
                                More →
                            </Link>
                        </div>

                        <Separator class="my-3" />
                    </div>

                    <!-- Admin Section (Collapsible) -->
                    <div>
                        <button
                            v-if="!sidebarCollapsed"
                            type="button"
                            class="flex justify-between items-center px-3 py-2 w-full font-semibold text-muted-foreground hover:text-foreground text-xs uppercase tracking-wider transition-colors"
                            @click="toggleAdmin"
                        >
                            <span>Admin</span>
                            <component
                                :is="adminExpanded ? ChevronDown : ChevronRight"
                                class="w-4 h-4"
                            />
                        </button>

                        <div
                            v-show="adminExpanded || sidebarCollapsed"
                            :class="['space-y-1', !sidebarCollapsed && 'mt-1']"
                        >
                            <Link
                                v-for="item in adminNavigation"
                                :key="item.name"
                                :href="item.href"
                                :class="[
                                    'flex items-center gap-3 rounded-md transition-colors',
                                    sidebarCollapsed
                                        ? 'justify-center p-2'
                                        : 'px-3 py-2 text-sm font-medium',
                                    isCurrentRoute(item.href)
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                ]"
                                :title="sidebarCollapsed ? item.name : undefined"
                                @click="mobileMenuOpen = false"
                            >
                                <component :is="item.icon" class="w-5 h-5" />
                                <span v-if="!sidebarCollapsed">{{ item.name }}</span>
                            </Link>
                        </div>
                    </div>
                </nav>

                <!-- Collapse Toggle (Desktop only) -->
                <div class="hidden lg:flex justify-end items-center p-3 border-t">
                    <Button
                        variant="ghost"
                        size="icon"
                        class="w-8 h-8"
                        :title="sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        @click="toggleSidebar"
                    >
                        <PanelLeftClose v-if="!sidebarCollapsed" class="w-4 h-4" />
                        <PanelLeft v-else class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </aside>

        <!-- Main content -->
        <div :class="['pt-14 transition-all duration-200', sidebarCollapsed ? 'lg:pl-16' : 'lg:pl-64']">
            <!-- Page content -->
            <main class="px-8 py-4">
                <slot />
            </main>
        </div>

        <!-- New Conversation Dialog -->
        <NewConversationDialog
            v-model:open="newConversationDialogOpen"
            :agents="agents"
        />
    </div>
</template>
