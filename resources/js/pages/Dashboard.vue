<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { AppLayout } from '@/layouts';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Bot,
    Wrench,
    Activity,
    TrendingUp,
    Plus,
    Cpu,
    CheckCircle2,
    XCircle,
    HelpCircle,
    ArrowUpRight,
    ArrowDownRight,
    Minus,
    MessageSquare,
    Star,
    Circle,
} from 'lucide-vue-next';
import type { Conversation, Agent } from '@/types';

interface AgentStats {
    total: number;
    active: number;
    inactive: number;
    error: number;
}

interface ConversationStats {
    total: number;
    completed: number;
    failed: number;
    active: number;
    cancelled: number;
    waitingTool: number;
    today: number;
    yesterday: number;
}

interface Stats {
    agents: AgentStats;
    conversations: ConversationStats;
    tools: number;
    successRate: number;
}

interface TopAgent extends Pick<Agent, 'id' | 'name' | 'status' | 'ai_backend'> {
    conversations_count: number;
}

interface BackendStatus {
    name: string;
    driver: string;
    is_default: boolean;
    status: 'connected' | 'error' | 'unknown';
}

const props = defineProps<{
    stats: Stats;
    topAgents: TopAgent[];
    recentConversations: Conversation[];
    backends?: BackendStatus[];
}>();

// Computed values
const todayTrend = computed(() => {
    const today = props.stats.conversations.today;
    const yesterday = props.stats.conversations.yesterday;
    if (yesterday === 0) return today > 0 ? 'up' : 'neutral';
    if (today > yesterday) return 'up';
    if (today < yesterday) return 'down';
    return 'neutral';
});

const maxConversationCount = computed(() => {
    return Math.max(...props.topAgents.map(a => a.conversations_count), 1);
});

const conversationStatusTotal = computed(() => {
    const c = props.stats.conversations;
    return c.completed + c.failed + c.active + c.cancelled + c.waitingTool;
});

// Helper functions
const formatRelativeTime = (date: string | null) => {
    if (!date) return '-';
    const now = new Date();
    const then = new Date(date);
    const diffMs = now.getTime() - then.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'Just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    if (diffHours < 24) return `${diffHours}h ago`;
    if (diffDays < 7) return `${diffDays}d ago`;
    return then.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
};

const getStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'text-blue-500',
        completed: 'text-green-500',
        failed: 'text-red-500',
        cancelled: 'text-muted-foreground',
        waiting_tool: 'text-yellow-500',
    };
    return colors[status] || 'text-muted-foreground';
};

const getStatusBgColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'bg-blue-500',
        completed: 'bg-green-500',
        failed: 'bg-red-500',
        cancelled: 'bg-gray-400',
        waiting_tool: 'bg-yellow-500',
    };
    return colors[status] || 'bg-gray-400';
};

const getDriverBadgeClass = (driver: string) => {
    const colors: Record<string, string> = {
        ollama: 'bg-purple-500/10 text-purple-600 border-purple-500/20',
        anthropic: 'bg-orange-500/10 text-orange-600 border-orange-500/20',
        openai: 'bg-green-500/10 text-green-600 border-green-500/20',
    };
    return colors[driver] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};

const getAgentStatusColor = (status: string) => {
    const colors: Record<string, string> = {
        active: 'text-green-500',
        inactive: 'text-muted-foreground',
        error: 'text-red-500',
    };
    return colors[status] || 'text-muted-foreground';
};

const getStatusPercent = (count: number) => {
    if (conversationStatusTotal.value === 0) return 0;
    return (count / conversationStatusTotal.value) * 100;
};
</script>

<template>
    <AppLayout title="Dashboard">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-semibold">Dashboard</h1>
                    <p class="text-sm text-muted-foreground">Overview of your AI agents and activity</p>
                </div>
                <div class="flex items-center gap-2">
                    <Button variant="outline" as-child>
                        <Link href="/agents/create">
                            <Bot class="h-4 w-4 mr-2" />
                            New Agent
                        </Link>
                    </Button>
                    <Button as-child>
                        <Link href="/conversations">
                            <Plus class="h-4 w-4 mr-2" />
                            New Chat
                        </Link>
                    </Button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
                <!-- Agents Card -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Agents</CardTitle>
                        <Bot class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.agents.active }}</div>
                        <div class="flex items-center gap-3 mt-1">
                            <span class="flex items-center gap-1 text-xs">
                                <Circle class="h-2 w-2 fill-green-500 text-green-500" />
                                {{ stats.agents.active }}
                            </span>
                            <span class="flex items-center gap-1 text-xs text-muted-foreground">
                                <Circle class="h-2 w-2 fill-gray-400 text-gray-400" />
                                {{ stats.agents.inactive }}
                            </span>
                            <span v-if="stats.agents.error > 0" class="flex items-center gap-1 text-xs text-red-500">
                                <Circle class="h-2 w-2 fill-red-500 text-red-500" />
                                {{ stats.agents.error }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ stats.agents.total }} total
                        </p>
                    </CardContent>
                </Card>

                <!-- Today's Activity Card -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Today</CardTitle>
                        <Activity class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="flex items-center gap-2">
                            <span class="text-2xl font-bold">{{ stats.conversations.today }}</span>
                            <span
                                v-if="todayTrend === 'up'"
                                class="flex items-center text-xs text-green-500"
                            >
                                <ArrowUpRight class="h-3 w-3" />
                            </span>
                            <span
                                v-else-if="todayTrend === 'down'"
                                class="flex items-center text-xs text-red-500"
                            >
                                <ArrowDownRight class="h-3 w-3" />
                            </span>
                            <span v-else class="flex items-center text-xs text-muted-foreground">
                                <Minus class="h-3 w-3" />
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ stats.conversations.yesterday }} yesterday
                        </p>
                    </CardContent>
                </Card>

                <!-- Tools Card -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Tools</CardTitle>
                        <Wrench class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.tools }}</div>
                        <p class="text-xs text-muted-foreground mt-1">Available for agents</p>
                    </CardContent>
                </Card>

                <!-- Success Rate Card -->
                <Card>
                    <CardHeader class="flex flex-row items-center justify-between pb-2">
                        <CardTitle class="text-sm font-medium">Success Rate</CardTitle>
                        <TrendingUp class="h-4 w-4 text-muted-foreground" />
                    </CardHeader>
                    <CardContent>
                        <div class="text-2xl font-bold">{{ stats.successRate }}%</div>
                        <Progress :model-value="stats.successRate" class="h-1.5 mt-2" />
                        <p class="text-xs text-muted-foreground mt-1">
                            {{ stats.conversations.completed }} / {{ stats.conversations.total }} completed
                        </p>
                    </CardContent>
                </Card>
            </div>

            <!-- Middle Section: Status Breakdown + AI Backends -->
            <div class="grid gap-4 lg:grid-cols-3">
                <!-- Conversation Status Breakdown -->
                <Card class="lg:col-span-2">
                    <CardHeader>
                        <CardTitle class="text-base">Conversation Status</CardTitle>
                        <CardDescription>Distribution of all conversations</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div v-if="conversationStatusTotal > 0">
                            <!-- Status Bar -->
                            <div class="flex h-3 rounded-full overflow-hidden bg-muted">
                                <div
                                    v-if="stats.conversations.completed > 0"
                                    class="bg-green-500"
                                    :style="{ width: `${getStatusPercent(stats.conversations.completed)}%` }"
                                />
                                <div
                                    v-if="stats.conversations.active > 0"
                                    class="bg-blue-500"
                                    :style="{ width: `${getStatusPercent(stats.conversations.active)}%` }"
                                />
                                <div
                                    v-if="stats.conversations.waitingTool > 0"
                                    class="bg-yellow-500"
                                    :style="{ width: `${getStatusPercent(stats.conversations.waitingTool)}%` }"
                                />
                                <div
                                    v-if="stats.conversations.failed > 0"
                                    class="bg-red-500"
                                    :style="{ width: `${getStatusPercent(stats.conversations.failed)}%` }"
                                />
                                <div
                                    v-if="stats.conversations.cancelled > 0"
                                    class="bg-gray-400"
                                    :style="{ width: `${getStatusPercent(stats.conversations.cancelled)}%` }"
                                />
                            </div>
                            <!-- Legend -->
                            <div class="flex flex-wrap gap-4 mt-3 text-sm">
                                <span v-if="stats.conversations.completed > 0" class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-green-500" />
                                    Completed ({{ stats.conversations.completed }})
                                </span>
                                <span v-if="stats.conversations.active > 0" class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-blue-500" />
                                    Active ({{ stats.conversations.active }})
                                </span>
                                <span v-if="stats.conversations.waitingTool > 0" class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-yellow-500" />
                                    Waiting ({{ stats.conversations.waitingTool }})
                                </span>
                                <span v-if="stats.conversations.failed > 0" class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-red-500" />
                                    Failed ({{ stats.conversations.failed }})
                                </span>
                                <span v-if="stats.conversations.cancelled > 0" class="flex items-center gap-1.5">
                                    <span class="h-2.5 w-2.5 rounded-full bg-gray-400" />
                                    Cancelled ({{ stats.conversations.cancelled }})
                                </span>
                            </div>
                        </div>
                        <div v-else class="text-center py-4 text-muted-foreground">
                            No conversations yet
                        </div>
                    </CardContent>
                </Card>

                <!-- AI Backends Status Panel -->
                <Card>
                    <CardHeader class="pb-3">
                        <div class="flex items-center justify-between">
                            <CardTitle class="text-base">AI Backends</CardTitle>
                            <Button variant="ghost" size="sm" as-child>
                                <Link href="/ai-backends">View All</Link>
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <!-- Loading skeleton -->
                        <div v-if="!backends" class="space-y-3">
                            <div v-for="i in 2" :key="i" class="flex items-center gap-3">
                                <Skeleton class="h-4 w-4 rounded-full" />
                                <Skeleton class="h-4 flex-1" />
                            </div>
                        </div>

                        <!-- Backend list -->
                        <div v-else-if="backends.length > 0" class="space-y-2">
                            <Link
                                v-for="backend in backends"
                                :key="backend.name"
                                :href="`/ai-backends/${backend.name}`"
                                class="flex items-center gap-3 p-2 -mx-2 rounded-md hover:bg-muted transition-colors"
                            >
                                <CheckCircle2
                                    v-if="backend.status === 'connected'"
                                    class="h-4 w-4 text-green-500 shrink-0"
                                />
                                <XCircle
                                    v-else-if="backend.status === 'error'"
                                    class="h-4 w-4 text-red-500 shrink-0"
                                />
                                <HelpCircle
                                    v-else
                                    class="h-4 w-4 text-muted-foreground shrink-0"
                                />
                                <span class="font-medium text-sm flex-1 truncate">{{ backend.name }}</span>
                                <Star
                                    v-if="backend.is_default"
                                    class="h-3.5 w-3.5 text-yellow-500 fill-yellow-500 shrink-0"
                                />
                                <Badge
                                    variant="outline"
                                    :class="['text-xs shrink-0', getDriverBadgeClass(backend.driver)]"
                                >
                                    {{ backend.driver }}
                                </Badge>
                            </Link>
                        </div>

                        <div v-else class="text-center py-4 text-muted-foreground text-sm">
                            No backends configured
                        </div>
                    </CardContent>
                </Card>
            </div>

            <!-- Top Agents Section -->
            <Card v-if="topAgents.length > 0">
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="text-base">Top Agents</CardTitle>
                            <CardDescription>Most active agents by conversation count</CardDescription>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link href="/agents">View All</Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <Link
                            v-for="agent in topAgents"
                            :key="agent.id"
                            :href="`/agents/${agent.id}`"
                            class="flex flex-col gap-2 p-3 rounded-lg border hover:border-primary/50 hover:bg-muted/50 transition-all"
                        >
                            <div class="flex items-center gap-2">
                                <Circle :class="['h-2 w-2 fill-current', getAgentStatusColor(agent.status)]" />
                                <span class="font-medium text-sm truncate">{{ agent.name }}</span>
                            </div>
                            <div class="space-y-1">
                                <div class="flex items-center justify-between text-xs text-muted-foreground">
                                    <span>{{ agent.conversations_count }} chats</span>
                                </div>
                                <div class="h-1.5 rounded-full bg-muted overflow-hidden">
                                    <div
                                        class="h-full bg-primary/60 rounded-full"
                                        :style="{ width: `${(agent.conversations_count / maxConversationCount) * 100}%` }"
                                    />
                                </div>
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Recent Conversations -->
            <Card>
                <CardHeader>
                    <div class="flex items-center justify-between">
                        <div>
                            <CardTitle class="text-base">Recent Conversations</CardTitle>
                            <CardDescription>Your latest agent conversations</CardDescription>
                        </div>
                        <Button variant="ghost" size="sm" as-child>
                            <Link href="/conversations">View All</Link>
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <div v-if="recentConversations.length === 0" class="text-center py-8">
                        <MessageSquare class="h-10 w-10 text-muted-foreground mx-auto mb-3" />
                        <p class="text-muted-foreground mb-4">No conversations yet</p>
                        <Button as-child>
                            <Link href="/conversations">Start a Conversation</Link>
                        </Button>
                    </div>

                    <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <Link
                            v-for="conversation in recentConversations"
                            :key="conversation.id"
                            :href="`/conversations/${conversation.id}`"
                            class="flex items-start gap-3 p-3 rounded-lg border hover:border-primary/50 hover:bg-muted/50 transition-all"
                        >
                            <div class="flex items-center justify-center h-9 w-9 rounded-full bg-primary/10 shrink-0">
                                <span class="text-xs font-medium text-primary">
                                    {{ (conversation.agent?.name || 'A')[0].toUpperCase() }}
                                </span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-sm truncate">
                                        {{ conversation.agent?.name || 'Unknown Agent' }}
                                    </span>
                                    <Circle :class="['h-2 w-2 fill-current shrink-0', getStatusColor(conversation.status)]" />
                                </div>
                                <div class="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
                                    <span>{{ conversation.turn_count }} turns</span>
                                    <span>·</span>
                                    <span>{{ formatRelativeTime(conversation.last_activity_at) }}</span>
                                </div>
                            </div>
                        </Link>
                    </div>
                </CardContent>
            </Card>

            <!-- Quick Start (when no agents) -->
            <Card v-if="stats.agents.total === 0" class="border-dashed">
                <CardContent class="flex flex-col items-center justify-center py-8">
                    <Bot class="h-12 w-12 text-muted-foreground mb-4" />
                    <h3 class="font-semibold mb-1">Create your first AI agent</h3>
                    <p class="text-sm text-muted-foreground mb-4 text-center max-w-sm">
                        AI agents can have conversations, use tools, and help automate your tasks.
                    </p>
                    <Button as-child>
                        <Link href="/agents/create">
                            <Plus class="h-4 w-4 mr-2" />
                            Create Agent
                        </Link>
                    </Button>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
