# Layout Components

Guidelines for the application layout including header bar, sidebar, and spacing system.

## Layout Architecture

```
┌─────────────────────────────────────────────────────────────┐
│  Header (h-14, fixed top)                                   │
│  [Logo] [Breadcrumbs...              ] [Theme] [Avatar ▼]   │
├──────────┬──────────────────────────────────────────────────┤
│ Sidebar  │                                                  │
│ (w-56    │   Main Content Area                              │
│ collapsed│   (p-4, scrollable)                              │
│ or w-64  │                                                  │
│ expanded)│                                                  │
│          │                                                  │
│          │                                                  │
│          │                                                  │
│          │                                                  │
└──────────┴──────────────────────────────────────────────────┘
```

## Header Bar

A new fixed top header that consolidates navigation and user controls.

### Component Structure

```vue
<!-- resources/js/components/AppHeader.vue -->

<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { Moon, Sun, ChevronDown } from 'lucide-vue-next';
import { useTheme } from '@/composables/useTheme';
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';

const { resolvedTheme, toggleTheme } = useTheme();
const page = usePage();
</script>

<template>
    <header class="fixed top-0 left-0 right-0 z-50 h-14 border-b border-border bg-background/80 backdrop-blur-sm">
        <div class="flex h-full items-center justify-between px-4">
            <!-- Left: Logo + Breadcrumbs -->
            <div class="flex items-center gap-4">
                <!-- Logo -->
                <Link href="/" class="flex items-center gap-2">
                    <img src="/logo.svg" alt="Logo" class="h-8 w-8" />
                    <span class="font-semibold text-foreground hidden sm:inline">
                        Chinese Worker
                    </span>
                </Link>

                <!-- Breadcrumbs (hidden on mobile) -->
                <div class="hidden md:block">
                    <Breadcrumbs />
                </div>
            </div>

            <!-- Right: Theme Toggle + User Menu -->
            <div class="flex items-center gap-2">
                <!-- Theme Toggle -->
                <Button
                    variant="ghost"
                    size="icon"
                    @click="toggleTheme"
                    class="h-9 w-9"
                >
                    <Sun v-if="resolvedTheme === 'dark'" class="h-4 w-4" />
                    <Moon v-else class="h-4 w-4" />
                    <span class="sr-only">Toggle theme</span>
                </Button>

                <!-- User Dropdown -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" class="flex items-center gap-2 px-2">
                            <Avatar class="h-8 w-8">
                                <AvatarImage :src="page.props.auth.user.avatar" />
                                <AvatarFallback>
                                    {{ page.props.auth.user.name.charAt(0).toUpperCase() }}
                                </AvatarFallback>
                            </Avatar>
                            <span class="hidden sm:inline text-sm">
                                {{ page.props.auth.user.name }}
                            </span>
                            <ChevronDown class="h-4 w-4 text-muted-foreground" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-48">
                        <DropdownMenuItem as-child>
                            <Link href="/settings/profile">Profile</Link>
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <Link href="/settings/api-tokens">API Tokens</Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child>
                            <Link href="/logout" method="post" as="button" class="w-full">
                                Log out
                            </Link>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </div>
    </header>
</template>
```

### Header Specifications

| Property | Value |
|----------|-------|
| Height | `h-14` (56px) |
| Position | Fixed top, full width |
| Background | `bg-background/80` with `backdrop-blur-sm` |
| Border | `border-b border-border` |
| Z-index | `z-50` |
| Logo size | `h-8 w-8` |
| Avatar size | `h-8 w-8` |

## Sidebar

Refined sidebar with compact navigation and distinct settings icons.

### Sidebar Specifications

| Property | Value |
|----------|-------|
| Width (collapsed) | `w-14` (56px) |
| Width (expanded) | `w-64` (256px) |
| Top offset | `top-14` (below header) |
| Height | `h-[calc(100vh-3.5rem)]` |

### Icon Updates for Settings

| Section | Old Icon | New Icon | Import |
|---------|----------|----------|--------|
| Profile | `User` | `User` | Same |
| Password | `Key` | `Lock` | `lucide-vue-next` |
| API Tokens | `Key` | `Terminal` | `lucide-vue-next` |
| Two-Factor | `Key` | `ShieldCheck` | `lucide-vue-next` |

### Sidebar Nav Items

```vue
<script setup lang="ts">
import {
    LayoutDashboard,
    Bot,
    MessageSquare,
    Settings,
    User,
    Lock,
    Terminal,
    ShieldCheck,
} from 'lucide-vue-next';

const navItems = [
    { name: 'Dashboard', href: '/dashboard', icon: LayoutDashboard },
    { name: 'Agents', href: '/agents', icon: Bot },
    { name: 'Conversations', href: '/conversations', icon: MessageSquare },
];

const settingsItems = [
    { name: 'Profile', href: '/settings/profile', icon: User },
    { name: 'Password', href: '/settings/password', icon: Lock },
    { name: 'API Tokens', href: '/settings/api-tokens', icon: Terminal },
    { name: 'Two-Factor Auth', href: '/settings/two-factor', icon: ShieldCheck },
];
</script>
```

## Spacing System

### Compact Padding Guidelines

| Context | Before | After |
|---------|--------|-------|
| Page container | `p-6` | `p-4` |
| Card padding | `p-6` | `p-4` |
| Card header | `p-4` | `p-3` |
| Table cell | `py-4 px-6` | `py-2.5 px-4` |
| Form fields | `space-y-6` | `space-y-4` |
| Section gap | `space-y-8` | `space-y-6` |

### Typography Scale

| Element | Before | After |
|---------|--------|-------|
| Page title | `text-2xl` | `text-xl` |
| Section title | `text-lg` | `text-base font-medium` |
| Card title | `text-lg` | `text-sm font-medium` |
| Body text | `text-base` | `text-sm` |
| Secondary text | `text-sm` | `text-xs` |
| Table header | `text-sm` | `text-xs uppercase tracking-wide` |

### Implementation Example

```vue
<!-- Before: Wide spacing -->
<div class="p-6 space-y-6">
    <h1 class="text-2xl font-bold">Agents</h1>
    <Card class="p-6">
        <CardHeader class="p-4">
            <CardTitle class="text-lg">Your Agents</CardTitle>
        </CardHeader>
    </Card>
</div>

<!-- After: Compact spacing -->
<div class="p-4 space-y-4">
    <h1 class="text-xl font-semibold">Agents</h1>
    <Card class="p-4">
        <CardHeader class="p-3">
            <CardTitle class="text-sm font-medium">Your Agents</CardTitle>
        </CardHeader>
    </Card>
</div>
```

## Main Content Area

### Layout Component Update

```vue
<!-- resources/js/layouts/AppLayout.vue -->

<script setup lang="ts">
import AppHeader from '@/components/AppHeader.vue';
import AppSidebar from '@/components/AppSidebar.vue';
</script>

<template>
    <div class="min-h-screen bg-background">
        <!-- Header -->
        <AppHeader />

        <!-- Sidebar + Content -->
        <div class="flex pt-14">
            <!-- Sidebar -->
            <AppSidebar />

            <!-- Main Content -->
            <main class="flex-1 ml-14 lg:ml-64 min-h-[calc(100vh-3.5rem)]">
                <div class="p-4">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
```

### Responsive Breakpoints

| Breakpoint | Sidebar | Header |
|------------|---------|--------|
| `< sm` (640px) | Hidden, hamburger menu | Minimal logo |
| `sm-lg` | Collapsed (w-14) | Full |
| `> lg` (1024px) | Expanded (w-64) | Full |

## Mobile Considerations

### Mobile Header

```vue
<!-- Mobile: Show hamburger menu instead of breadcrumbs -->
<template>
    <div class="flex md:hidden">
        <Button variant="ghost" size="icon" @click="openMobileMenu">
            <Menu class="h-5 w-5" />
        </Button>
    </div>

    <div class="hidden md:block">
        <Breadcrumbs />
    </div>
</template>
```

### Mobile Sidebar (Sheet)

On mobile, sidebar becomes a sheet that slides in from the left:

```vue
<Sheet v-model:open="isMobileMenuOpen">
    <SheetContent side="left" class="w-64 p-0">
        <nav class="flex flex-col h-full">
            <!-- Same nav items as desktop sidebar -->
        </nav>
    </SheetContent>
</Sheet>
```

## Z-Index Scale

| Element | Z-index |
|---------|---------|
| Sidebar | `z-40` |
| Header | `z-50` |
| Dropdown menus | `z-50` |
| Sheets/Modals | `z-50` |
| Toasts | `z-[100]` |
