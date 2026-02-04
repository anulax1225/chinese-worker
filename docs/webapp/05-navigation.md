# Navigation

Breadcrumbs and routing patterns for the application.

## Breadcrumbs Component

### Design

```
Dashboard / Agents / Claude Assistant / Edit
    ↑          ↑            ↑            ↑
  Clickable  Clickable   Clickable    Current (not linked)
```

### Component Implementation

```vue
<!-- resources/js/components/Breadcrumbs.vue -->

<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { ChevronRight, Home } from 'lucide-vue-next';

interface BreadcrumbItem {
    label: string;
    href?: string;
}

const page = usePage();

// Get breadcrumbs from page props or generate from URL
const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    // Check if page provides explicit breadcrumbs
    if (page.props.breadcrumbs) {
        return page.props.breadcrumbs as BreadcrumbItem[];
    }

    // Generate from URL path
    return generateFromPath(page.url);
});

function generateFromPath(url: string): BreadcrumbItem[] {
    const path = url.split('?')[0]; // Remove query params
    const segments = path.split('/').filter(Boolean);

    const items: BreadcrumbItem[] = [];
    let currentPath = '';

    for (let i = 0; i < segments.length; i++) {
        const segment = segments[i];
        currentPath += '/' + segment;

        // Convert segment to label (e.g., "agents" → "Agents", "123" → keep as is)
        const label = isNaN(Number(segment))
            ? formatSegment(segment)
            : getEntityName(segments[i - 1], segment) || segment;

        // Last item has no href (current page)
        const isLast = i === segments.length - 1;

        items.push({
            label,
            href: isLast ? undefined : currentPath,
        });
    }

    return items;
}

function formatSegment(segment: string): string {
    return segment
        .replace(/-/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase());
}

function getEntityName(entityType: string | undefined, id: string): string | null {
    // Try to get entity name from page props
    const props = page.props as Record<string, any>;

    if (entityType === 'agents' && props.agent) {
        return props.agent.name;
    }
    if (entityType === 'conversations' && props.conversation) {
        return `Conversation #${id}`;
    }
    if (entityType === 'tools' && props.tool) {
        return props.tool.name;
    }

    return null;
}
</script>

<template>
    <nav aria-label="Breadcrumb" class="flex items-center text-sm">
        <!-- Home icon -->
        <Link
            href="/dashboard"
            class="text-muted-foreground hover:text-foreground transition-colors"
        >
            <Home class="h-4 w-4" />
            <span class="sr-only">Home</span>
        </Link>

        <template v-for="(item, index) in breadcrumbs" :key="index">
            <!-- Separator -->
            <ChevronRight class="h-4 w-4 mx-2 text-muted-foreground/50" />

            <!-- Breadcrumb item -->
            <Link
                v-if="item.href"
                :href="item.href"
                class="text-muted-foreground hover:text-foreground transition-colors truncate max-w-[150px]"
                :title="item.label"
            >
                {{ item.label }}
            </Link>
            <span
                v-else
                class="text-foreground font-medium truncate max-w-[200px]"
                :title="item.label"
            >
                {{ item.label }}
            </span>
        </template>
    </nav>
</template>
```

### Providing Breadcrumbs from Controllers

```php
// app/Http/Controllers/Web/AgentController.php

public function show(Agent $agent)
{
    return Inertia::render('Agents/Show', [
        'agent' => $agent->load(['tools']),
        'breadcrumbs' => [
            ['label' => 'Agents', 'href' => '/agents'],
            ['label' => $agent->name], // No href = current page
        ],
    ]);
}

public function edit(Agent $agent)
{
    return Inertia::render('Agents/Edit', [
        'agent' => $agent,
        'breadcrumbs' => [
            ['label' => 'Agents', 'href' => '/agents'],
            ['label' => $agent->name, 'href' => "/agents/{$agent->id}"],
            ['label' => 'Edit'],
        ],
    ]);
}
```

### TypeScript Type Definition

```typescript
// resources/js/types/index.ts

export interface BreadcrumbItem {
    label: string;
    href?: string;
}

declare module '@inertiajs/core' {
    interface PageProps {
        breadcrumbs?: BreadcrumbItem[];
    }
}
```

## Mobile Navigation

### Mobile Breadcrumbs

On mobile, show only the back arrow and current page title:

```vue
<template>
    <!-- Desktop: Full breadcrumbs -->
    <nav class="hidden md:flex items-center text-sm">
        <!-- Full breadcrumb trail -->
    </nav>

    <!-- Mobile: Back + Current -->
    <nav class="flex md:hidden items-center gap-2">
        <Link
            v-if="breadcrumbs.length > 1"
            :href="breadcrumbs[breadcrumbs.length - 2]?.href || '/dashboard'"
            class="p-1 -ml-1 text-muted-foreground hover:text-foreground"
        >
            <ChevronLeft class="h-5 w-5" />
            <span class="sr-only">Back</span>
        </Link>
        <span class="font-medium truncate">
            {{ breadcrumbs[breadcrumbs.length - 1]?.label }}
        </span>
    </nav>
</template>
```

### Mobile Menu Sheet

```vue
<!-- resources/js/components/MobileNav.vue -->

<script setup lang="ts">
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { Menu, LayoutDashboard, Bot, MessageSquare, Settings } from 'lucide-vue-next';

const isOpen = ref(false);

const navItems = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Agents', href: '/agents', icon: Bot },
    { name: 'Conversations', href: '/conversations', icon: MessageSquare },
    { name: 'Settings', href: '/settings/profile', icon: Settings },
];
</script>

<template>
    <div class="md:hidden">
        <Button variant="ghost" size="icon" @click="isOpen = true">
            <Menu class="h-5 w-5" />
            <span class="sr-only">Open menu</span>
        </Button>

        <Sheet v-model:open="isOpen">
            <SheetContent side="left" class="w-64 p-0">
                <SheetHeader class="p-4 border-b border-border">
                    <SheetTitle class="flex items-center gap-2">
                        <img src="/logo.svg" class="h-6 w-6" />
                        Chinese Worker
                    </SheetTitle>
                </SheetHeader>

                <nav class="p-2">
                    <Link
                        v-for="item in navItems"
                        :key="item.name"
                        :href="item.href"
                        class="flex items-center gap-3 px-3 py-2.5 rounded-md text-sm font-medium text-muted-foreground hover:text-foreground hover:bg-muted transition-colors"
                        @click="isOpen = false"
                    >
                        <component :is="item.icon" class="h-5 w-5" />
                        {{ item.name }}
                    </Link>
                </nav>
            </SheetContent>
        </Sheet>
    </div>
</template>
```

## Route Structure

### URL Patterns

| Page | URL | Breadcrumb Trail |
|------|-----|------------------|
| Dashboard | `/dashboard` | Dashboard |
| Agents List | `/agents` | Agents |
| Agent Show | `/agents/{id}` | Agents / Agent Name |
| Agent Edit | `/agents/{id}/edit` | Agents / Agent Name / Edit |
| Tool Create | `/agents/{id}/tools/create` | Agents / Agent Name / Tools / Create |
| Conversations | `/conversations` | Conversations |
| Conversation | `/conversations/{id}` | Conversations / Conversation #123 |
| Settings | `/settings/profile` | Settings / Profile |
| API Tokens | `/settings/api-tokens` | Settings / API Tokens |

### Named Routes

```php
// routes/web.php

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::resource('agents', AgentController::class);
    Route::resource('agents.tools', ToolController::class)->shallow();

    Route::resource('conversations', ConversationController::class)->only(['index', 'show', 'store']);

    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile');
        Route::get('/password', [PasswordController::class, 'edit'])->name('password');
        Route::get('/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens');
        Route::get('/two-factor', [TwoFactorController::class, 'edit'])->name('two-factor');
    });
});
```

## Active State Detection

### Sidebar Active Link

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();

interface NavItem {
    name: string;
    href: string;
    icon: Component;
    matchPattern?: RegExp;
}

const navItems: NavItem[] = [
    {
        name: 'Dashboard',
        href: '/dashboard',
        icon: LayoutDashboard,
        matchPattern: /^\/dashboard/,
    },
    {
        name: 'Agents',
        href: '/agents',
        icon: Bot,
        matchPattern: /^\/agents/,
    },
    {
        name: 'Conversations',
        href: '/conversations',
        icon: MessageSquare,
        matchPattern: /^\/conversations/,
    },
];

const isActive = (item: NavItem): boolean => {
    const currentPath = page.url.split('?')[0];

    if (item.matchPattern) {
        return item.matchPattern.test(currentPath);
    }

    return currentPath === item.href || currentPath.startsWith(item.href + '/');
};
</script>

<template>
    <nav>
        <Link
            v-for="item in navItems"
            :key="item.name"
            :href="item.href"
            :class="[
                'flex items-center gap-3 px-3 py-2 rounded-md text-sm font-medium transition-colors',
                isActive(item)
                    ? 'bg-primary/10 text-primary'
                    : 'text-muted-foreground hover:text-foreground hover:bg-muted'
            ]"
        >
            <component :is="item.icon" class="h-5 w-5" />
            {{ item.name }}
        </Link>
    </nav>
</template>
```

## Page Transitions

### Smooth Navigation

```vue
<!-- resources/js/app.ts -->

import { createInertiaApp } from '@inertiajs/vue3';

createInertiaApp({
    progress: {
        color: 'var(--primary)',
        showSpinner: false,
    },
    // ...
});
```

### Loading State

```vue
<!-- AppLayout.vue -->

<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';

const isNavigating = ref(false);

onMounted(() => {
    router.on('start', () => isNavigating.value = true);
    router.on('finish', () => isNavigating.value = false);
});
</script>

<template>
    <div>
        <!-- Progress bar -->
        <div
            v-if="isNavigating"
            class="fixed top-0 left-0 right-0 h-0.5 bg-primary z-[100]"
        >
            <div class="h-full bg-primary/50 animate-progress" />
        </div>

        <!-- Content -->
        <slot />
    </div>
</template>

<style>
@keyframes progress {
    0% { width: 0%; }
    50% { width: 70%; }
    100% { width: 100%; }
}

.animate-progress {
    animation: progress 2s ease-in-out infinite;
}
</style>
```
