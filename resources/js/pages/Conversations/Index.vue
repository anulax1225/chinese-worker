<script setup lang="ts">
import { Link, router, usePage, WhenVisible } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import NewConversationDialog from '@/components/NewConversationDialog.vue';
import {
    Plus,
    Search,
    MoreHorizontal,
    Trash2,
    MessageSquare,
    Filter,
    X,
    Loader2,
} from 'lucide-vue-next';
import { destroy } from '@/actions/App/Http/Controllers/Api/V1/ConversationController';
import type { Conversation, Agent, SharedAgent } from '@/types';

interface Filters {
    status: string | null;
    agent_id: string | null;
    search: string | null;
}

interface GroupedConversations {
    today: Conversation[];
    yesterday: Conversation[];
    previousWeek: Conversation[];
    previousMonth: Conversation[];
    older: Conversation[];
}

const props = defineProps<{
    conversations: Conversation[];
    nextCursor: string | null;
    agents: Pick<Agent, 'id' | 'name'>[];
    filters: Filters;
}>();

const page = usePage();
const sharedAgents = computed(() => page.props.agents as SharedAgent[]);

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const agentId = ref(props.filters.agent_id || 'all');
const showFilters = ref(false);
const newConversationDialogOpen = ref(false);
const deleting = ref<number | null>(null);

const hasActiveFilters = computed(() => {
    return props.filters.search || props.filters.status || props.filters.agent_id;
});

// Build URL params for loading more
const loadMoreParams = computed(() => {
    const params: Record<string, string> = {};
    if (props.nextCursor) {
        params.cursor = props.nextCursor;
    }
    if (props.filters.search) {
        params.search = props.filters.search;
    }
    if (props.filters.status) {
        params.status = props.filters.status;
    }
    if (props.filters.agent_id) {
        params.agent_id = props.filters.agent_id;
    }
    return params;
});

const applyFilters = () => {
    router.get('/conversations', {
        search: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
        agent_id: agentId.value === 'all' ? undefined : agentId.value,
    }, {
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    search.value = '';
    status.value = 'all';
    agentId.value = 'all';
    router.get('/conversations', {}, {
        preserveState: false,
        replace: true,
    });
};

const deleteConversation = async (conversation: Conversation, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (!confirm('Are you sure you want to delete this conversation?')) {
        return;
    }

    deleting.value = conversation.id;
    try {
        const response = await fetch(destroy.url(conversation.id), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });

        if (response.ok) {
            router.reload({ only: ['conversations', 'nextCursor'] });
        }
    } finally {
        deleting.value = null;
    }
};

const getStatusDot = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-blue-500',
        completed: 'bg-green-500',
        failed: 'bg-red-500',
        cancelled: 'bg-muted-foreground',
    };
    return colors[status] || 'bg-muted-foreground';
};

// Group conversations by time
const groupedConversations = computed<GroupedConversations>(() => {
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    const weekAgo = new Date(today);
    weekAgo.setDate(weekAgo.getDate() - 7);
    const monthAgo = new Date(today);
    monthAgo.setDate(monthAgo.getDate() - 30);

    const groups: GroupedConversations = {
        today: [],
        yesterday: [],
        previousWeek: [],
        previousMonth: [],
        older: [],
    };

    props.conversations.forEach((conversation) => {
        const date = new Date(conversation.last_activity_at || conversation.created_at);

        if (date >= today) {
            groups.today.push(conversation);
        } else if (date >= yesterday) {
            groups.yesterday.push(conversation);
        } else if (date >= weekAgo) {
            groups.previousWeek.push(conversation);
        } else if (date >= monthAgo) {
            groups.previousMonth.push(conversation);
        } else {
            groups.older.push(conversation);
        }
    });

    return groups;
});

const formatTime = (date: string | null) => {
    if (!date) return '';
    const d = new Date(date);
    const now = new Date();
    const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());

    if (d >= today) {
        return d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
    }
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const getInitials = (name: string | undefined) => {
    if (!name) return 'A';
    return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2);
};

const formatTokens = (count: number): string => {
    if (count >= 1000) {
        return `${(count / 1000).toFixed(1)}K`;
    }
    return count.toString();
};

// Sync filter refs when props change (after navigation)
watch(() => props.filters, (newFilters) => {
    search.value = newFilters.search || '';
    status.value = newFilters.status || 'all';
    agentId.value = newFilters.agent_id || 'all';
}, { immediate: true });
</script>

<template>
    <AppLayout title="Conversations">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">Conversations</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ conversations.length }} loaded
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        :class="{ 'bg-accent': showFilters || hasActiveFilters }"
                        @click="showFilters = !showFilters"
                    >
                        <Filter class="h-4 w-4" />
                    </Button>
                    <Button @click="newConversationDialogOpen = true">
                        <Plus class="h-4 w-4 mr-2" />
                        New Chat
                    </Button>
                </div>
            </div>

            <!-- Filters (collapsible) -->
            <div
                v-if="showFilters"
                class="mb-6 p-4 bg-muted/50 rounded-lg border"
            >
                <div class="flex items-center justify-between mb-3">
                    <span class="text-sm font-medium">Filters</span>
                    <Button
                        v-if="hasActiveFilters"
                        variant="ghost"
                        size="sm"
                        class="h-7 text-xs"
                        @click="clearFilters"
                    >
                        <X class="h-3 w-3 mr-1" />
                        Clear all
                    </Button>
                </div>
                <div class="flex gap-3 flex-wrap">
                    <div class="relative flex-1 min-w-50">
                        <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                        <Input
                            v-model="search"
                            placeholder="Search conversations..."
                            class="pl-8"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <Select v-model="status" @update:model-value="applyFilters">
                        <SelectTrigger class="w-35">
                            <SelectValue placeholder="Status" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Status</SelectItem>
                            <SelectItem value="active">Active</SelectItem>
                            <SelectItem value="completed">Completed</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                        </SelectContent>
                    </Select>
                    <Select v-model="agentId" @update:model-value="applyFilters">
                        <SelectTrigger class="w-40">
                            <SelectValue placeholder="Agent" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Agents</SelectItem>
                            <SelectItem v-for="agent in agents" :key="agent.id" :value="String(agent.id)">
                                {{ agent.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="conversations.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <MessageSquare class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No conversations yet</h3>
                <p class="text-muted-foreground mb-6">
                    Start a new conversation with one of your agents.
                </p>
                <Button @click="newConversationDialogOpen = true">
                    <Plus class="h-4 w-4 mr-2" />
                    New Conversation
                </Button>
            </div>

            <!-- Conversation Cards -->
            <div v-else class="space-y-8">
                <!-- Today -->
                <div v-if="groupedConversations.today.length > 0">
                    <h2 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-3">
                        Today
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="conversation in groupedConversations.today"
                            :key="conversation.id"
                            :href="`/conversations/${conversation.id}`"
                            class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                        >
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="absolute top-2 right-2 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                        @click.prevent
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="deleteConversation(conversation, $event)"
                                        class="cursor-pointer text-destructive"
                                        :disabled="deleting === conversation.id"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        {{ deleting === conversation.id ? 'Deleting...' : 'Delete' }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <div class="flex items-start gap-3">
                                <Avatar class="h-9 w-9 shrink-0">
                                    <AvatarFallback class="bg-primary/10 text-primary text-xs">
                                        {{ getInitials(conversation.agent?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="flex-1 min-w-0 pr-6">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-sm truncate">
                                            {{ conversation.agent?.name || 'Unknown Agent' }}
                                        </span>
                                        <div :class="['h-1.5 w-1.5 rounded-full shrink-0', getStatusDot(conversation.status)]" />
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>{{ conversation.turn_count }} turns</span>
                                        <template v-if="conversation.token_usage?.total_tokens">
                                            <span>·</span>
                                            <span>{{ formatTokens(conversation.token_usage.total_tokens) }} tokens</span>
                                        </template>
                                        <span>·</span>
                                        <span>{{ formatTime(conversation.last_activity_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Yesterday -->
                <div v-if="groupedConversations.yesterday.length > 0">
                    <h2 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-3">
                        Yesterday
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="conversation in groupedConversations.yesterday"
                            :key="conversation.id"
                            :href="`/conversations/${conversation.id}`"
                            class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                        >
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="absolute top-2 right-2 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                        @click.prevent
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="deleteConversation(conversation, $event)"
                                        class="cursor-pointer text-destructive"
                                        :disabled="deleting === conversation.id"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        {{ deleting === conversation.id ? 'Deleting...' : 'Delete' }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <div class="flex items-start gap-3">
                                <Avatar class="h-9 w-9 shrink-0">
                                    <AvatarFallback class="bg-primary/10 text-primary text-xs">
                                        {{ getInitials(conversation.agent?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="flex-1 min-w-0 pr-6">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-sm truncate">
                                            {{ conversation.agent?.name || 'Unknown Agent' }}
                                        </span>
                                        <div :class="['h-1.5 w-1.5 rounded-full shrink-0', getStatusDot(conversation.status)]" />
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>{{ conversation.turn_count }} turns</span>
                                        <template v-if="conversation.token_usage?.total_tokens">
                                            <span>·</span>
                                            <span>{{ formatTokens(conversation.token_usage.total_tokens) }} tokens</span>
                                        </template>
                                        <span>·</span>
                                        <span>{{ formatTime(conversation.last_activity_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Previous 7 Days -->
                <div v-if="groupedConversations.previousWeek.length > 0">
                    <h2 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-3">
                        Previous 7 Days
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="conversation in groupedConversations.previousWeek"
                            :key="conversation.id"
                            :href="`/conversations/${conversation.id}`"
                            class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                        >
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="absolute top-2 right-2 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                        @click.prevent
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="deleteConversation(conversation, $event)"
                                        class="cursor-pointer text-destructive"
                                        :disabled="deleting === conversation.id"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        {{ deleting === conversation.id ? 'Deleting...' : 'Delete' }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <div class="flex items-start gap-3">
                                <Avatar class="h-9 w-9 shrink-0">
                                    <AvatarFallback class="bg-primary/10 text-primary text-xs">
                                        {{ getInitials(conversation.agent?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="flex-1 min-w-0 pr-6">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-sm truncate">
                                            {{ conversation.agent?.name || 'Unknown Agent' }}
                                        </span>
                                        <div :class="['h-1.5 w-1.5 rounded-full shrink-0', getStatusDot(conversation.status)]" />
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>{{ conversation.turn_count }} turns</span>
                                        <template v-if="conversation.token_usage?.total_tokens">
                                            <span>·</span>
                                            <span>{{ formatTokens(conversation.token_usage.total_tokens) }} tokens</span>
                                        </template>
                                        <span>·</span>
                                        <span>{{ formatTime(conversation.last_activity_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Previous 30 Days -->
                <div v-if="groupedConversations.previousMonth.length > 0">
                    <h2 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-3">
                        Previous 30 Days
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="conversation in groupedConversations.previousMonth"
                            :key="conversation.id"
                            :href="`/conversations/${conversation.id}`"
                            class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                        >
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="absolute top-2 right-2 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                        @click.prevent
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="deleteConversation(conversation, $event)"
                                        class="cursor-pointer text-destructive"
                                        :disabled="deleting === conversation.id"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        {{ deleting === conversation.id ? 'Deleting...' : 'Delete' }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <div class="flex items-start gap-3">
                                <Avatar class="h-9 w-9 shrink-0">
                                    <AvatarFallback class="bg-primary/10 text-primary text-xs">
                                        {{ getInitials(conversation.agent?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="flex-1 min-w-0 pr-6">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-sm truncate">
                                            {{ conversation.agent?.name || 'Unknown Agent' }}
                                        </span>
                                        <div :class="['h-1.5 w-1.5 rounded-full shrink-0', getStatusDot(conversation.status)]" />
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>{{ conversation.turn_count }} turns</span>
                                        <template v-if="conversation.token_usage?.total_tokens">
                                            <span>·</span>
                                            <span>{{ formatTokens(conversation.token_usage.total_tokens) }} tokens</span>
                                        </template>
                                        <span>·</span>
                                        <span>{{ formatTime(conversation.last_activity_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Older -->
                <div v-if="groupedConversations.older.length > 0">
                    <h2 class="text-xs font-medium text-muted-foreground uppercase tracking-wider mb-3">
                        Older
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="conversation in groupedConversations.older"
                            :key="conversation.id"
                            :href="`/conversations/${conversation.id}`"
                            class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                        >
                            <DropdownMenu>
                                <DropdownMenuTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="absolute top-2 right-2 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                        @click.prevent
                                    >
                                        <MoreHorizontal class="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end">
                                    <DropdownMenuItem
                                        @click="deleteConversation(conversation, $event)"
                                        class="cursor-pointer text-destructive"
                                        :disabled="deleting === conversation.id"
                                    >
                                        <Trash2 class="mr-2 h-4 w-4" />
                                        {{ deleting === conversation.id ? 'Deleting...' : 'Delete' }}
                                    </DropdownMenuItem>
                                </DropdownMenuContent>
                            </DropdownMenu>
                            <div class="flex items-start gap-3">
                                <Avatar class="h-9 w-9 shrink-0">
                                    <AvatarFallback class="bg-primary/10 text-primary text-xs">
                                        {{ getInitials(conversation.agent?.name) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="flex-1 min-w-0 pr-6">
                                    <div class="flex items-center gap-2 mb-1">
                                        <span class="font-medium text-sm truncate">
                                            {{ conversation.agent?.name || 'Unknown Agent' }}
                                        </span>
                                        <div :class="['h-1.5 w-1.5 rounded-full shrink-0', getStatusDot(conversation.status)]" />
                                    </div>
                                    <div class="flex items-center gap-2 text-xs text-muted-foreground">
                                        <span>{{ conversation.turn_count }} turns</span>
                                        <template v-if="conversation.token_usage?.total_tokens">
                                            <span>·</span>
                                            <span>{{ formatTokens(conversation.token_usage.total_tokens) }} tokens</span>
                                        </template>
                                        <span>·</span>
                                        <span>{{ formatTime(conversation.last_activity_at) }}</span>
                                    </div>
                                </div>
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="nextCursor"
                    :data="['conversations', 'nextCursor']"
                    :params="loadMoreParams"
                    :options="{ rootMargin: '200px 0px' }"
                >
                    <div class="flex justify-center py-6">
                        <div class="flex items-center gap-2 text-sm text-muted-foreground">
                            <Loader2 class="h-4 w-4 animate-spin" />
                            Loading more...
                        </div>
                    </div>
                </WhenVisible>

                <!-- End of list indicator -->
                <div v-else-if="conversations.length > 0" class="text-center py-6 text-sm text-muted-foreground">
                    You've reached the end
                </div>
            </div>
        </div>

        <!-- New Conversation Dialog -->
        <NewConversationDialog
            v-model:open="newConversationDialogOpen"
            :agents="sharedAgents"
        />
    </AppLayout>
</template>
