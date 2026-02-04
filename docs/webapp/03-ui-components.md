# UI Components

Guidelines for sheets, cards, shadows, and other UI components.

## Sheets for Create/Edit

Replace centered dialogs with slide-in sheets for all create and edit operations.

### Why Sheets?

| Dialog | Sheet |
|--------|-------|
| Limited width | Full-height, wider content |
| Centered, blocking | Slides from edge, less intrusive |
| Scrolling issues | Natural scroll within sheet |
| Mobile: full screen | Mobile: full screen (same) |

### Sheet Specifications

| Property | Value |
|----------|-------|
| Width | `w-[480px]` (desktop) |
| Width (mobile) | Full width |
| Animation | Slide from right |
| Backdrop | `bg-black/50` with blur |
| Header | Sticky, with close button |
| Footer | Sticky, with action buttons |

### Sheet Component Usage

```vue
<script setup lang="ts">
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Button } from '@/components/ui/button';
import { ref } from 'vue';

const isOpen = ref(false);
</script>

<template>
    <Sheet v-model:open="isOpen">
        <SheetTrigger as-child>
            <Button>Create Agent</Button>
        </SheetTrigger>

        <SheetContent class="w-[480px] sm:max-w-[480px]">
            <SheetHeader>
                <SheetTitle>Create Agent</SheetTitle>
                <SheetDescription>
                    Configure a new AI agent with custom tools and prompts.
                </SheetDescription>
            </SheetHeader>

            <!-- Form content -->
            <div class="py-4 space-y-4">
                <!-- Form fields... -->
            </div>

            <SheetFooter>
                <Button variant="outline" @click="isOpen = false">
                    Cancel
                </Button>
                <Button type="submit">
                    Create Agent
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
```

### Pages to Convert

| Page | Current | Convert To |
|------|---------|------------|
| Create Agent | Separate page | Sheet from agents list |
| Edit Agent | Separate page | Sheet from agent show |
| Create Tool | Separate page | Sheet from agent show |
| Edit Tool | Separate page | Sheet from agent show |
| New Conversation | Dialog | Sheet from conversations list |
| API Token | Dialog | Sheet from settings |

## Shadow System

Refined shadows using OKLCH for consistent dark mode support.

### CSS Variables

```css
/* resources/css/app.css */

:root {
    /* Shadow scale - subtle and layered */
    --shadow-xs: 0 1px 2px oklch(0 0 0 / 0.04);
    --shadow-sm: 0 1px 3px oklch(0 0 0 / 0.06),
                 0 1px 2px oklch(0 0 0 / 0.04);
    --shadow-md: 0 4px 6px oklch(0 0 0 / 0.05),
                 0 2px 4px oklch(0 0 0 / 0.03);
    --shadow-lg: 0 10px 15px oklch(0 0 0 / 0.08),
                 0 4px 6px oklch(0 0 0 / 0.04);
    --shadow-xl: 0 20px 25px oklch(0 0 0 / 0.10),
                 0 8px 10px oklch(0 0 0 / 0.05);

    /* Colored shadows for interactive elements */
    --shadow-primary: 0 4px 14px oklch(0.623 0.214 var(--color-primary-hue) / 0.25);
    --shadow-secondary: 0 4px 14px oklch(0.541 0.245 var(--color-secondary-hue) / 0.25);
}

.dark {
    /* Shadows are more subtle in dark mode */
    --shadow-xs: 0 1px 2px oklch(0 0 0 / 0.2);
    --shadow-sm: 0 1px 3px oklch(0 0 0 / 0.25),
                 0 1px 2px oklch(0 0 0 / 0.2);
    --shadow-md: 0 4px 6px oklch(0 0 0 / 0.2),
                 0 2px 4px oklch(0 0 0 / 0.15);
    --shadow-lg: 0 10px 15px oklch(0 0 0 / 0.25),
                 0 4px 6px oklch(0 0 0 / 0.15);
    --shadow-xl: 0 20px 25px oklch(0 0 0 / 0.3),
                 0 8px 10px oklch(0 0 0 / 0.2);
}
```

### Tailwind Integration

```js
// tailwind.config.js (or inline in CSS with @theme)

@theme {
    --shadow-xs: var(--shadow-xs);
    --shadow-sm: var(--shadow-sm);
    --shadow-md: var(--shadow-md);
    --shadow-lg: var(--shadow-lg);
    --shadow-xl: var(--shadow-xl);
}
```

### Shadow Usage

```vue
<template>
    <!-- No shadow (flat design, use border) -->
    <div class="border border-border rounded-lg">
        Flat card
    </div>

    <!-- Subtle shadow (default cards) -->
    <div class="shadow-sm rounded-lg">
        Standard card
    </div>

    <!-- Elevated (dropdowns, popovers) -->
    <div class="shadow-lg rounded-lg">
        Elevated element
    </div>

    <!-- Interactive hover -->
    <div class="shadow-sm hover:shadow-md transition-shadow">
        Hoverable card
    </div>

    <!-- Primary accent shadow (CTA buttons) -->
    <button class="bg-primary shadow-primary hover:shadow-lg">
        Primary action
    </button>
</template>
```

## Cards

### Card Design System

| Variant | Border | Shadow | Use Case |
|---------|--------|--------|----------|
| Flat | `border-border` | None | Default containers |
| Elevated | None | `shadow-sm` | Floating content |
| Interactive | `border-border` | `hover:shadow-md` | Clickable items |
| Highlighted | `border-primary` | `shadow-primary` | Featured content |

### Card Component

```vue
<script setup lang="ts">
import { cva, type VariantProps } from 'class-variance-authority';

const cardVariants = cva(
    'rounded-lg bg-card text-card-foreground transition-all',
    {
        variants: {
            variant: {
                flat: 'border border-border',
                elevated: 'shadow-sm',
                interactive: 'border border-border hover:shadow-md hover:border-border/80 cursor-pointer',
                highlighted: 'border-2 border-primary shadow-primary',
            },
            padding: {
                none: '',
                sm: 'p-3',
                default: 'p-4',
                lg: 'p-6',
            },
        },
        defaultVariants: {
            variant: 'flat',
            padding: 'default',
        },
    }
);

type CardVariants = VariantProps<typeof cardVariants>;

defineProps<{
    variant?: CardVariants['variant'];
    padding?: CardVariants['padding'];
}>();
</script>

<template>
    <div :class="cardVariants({ variant, padding })">
        <slot />
    </div>
</template>
```

### Card Usage Examples

```vue
<template>
    <!-- Agent card (interactive) -->
    <Card variant="interactive" padding="default" @click="goToAgent">
        <div class="flex items-center gap-3">
            <Avatar>
                <AvatarFallback>A</AvatarFallback>
            </Avatar>
            <div>
                <h3 class="font-medium">Claude Assistant</h3>
                <p class="text-xs text-muted-foreground">4 tools</p>
            </div>
        </div>
    </Card>

    <!-- Stats card (flat) -->
    <Card variant="flat" padding="sm">
        <p class="text-xs text-muted-foreground">Total Conversations</p>
        <p class="text-2xl font-bold">1,234</p>
    </Card>

    <!-- Featured card (highlighted) -->
    <Card variant="highlighted" padding="default">
        <Badge class="mb-2">New</Badge>
        <h3 class="font-medium">Pro Features</h3>
        <p class="text-sm text-muted-foreground">Unlock advanced tools</p>
    </Card>
</template>
```

## Buttons

### Button Variants

| Variant | Use Case | Styling |
|---------|----------|---------|
| `default` | Primary actions | `bg-primary text-primary-foreground` |
| `secondary` | Secondary actions | `bg-secondary text-secondary-foreground` |
| `outline` | Tertiary actions | `border border-input bg-background` |
| `ghost` | Subtle actions | `hover:bg-accent` |
| `destructive` | Dangerous actions | `bg-destructive text-destructive-foreground` |
| `link` | Inline links | `text-primary underline-offset-4` |

### Button Sizes

| Size | Height | Padding | Font |
|------|--------|---------|------|
| `sm` | `h-8` | `px-3` | `text-xs` |
| `default` | `h-9` | `px-4` | `text-sm` |
| `lg` | `h-10` | `px-6` | `text-sm` |
| `icon` | `h-9 w-9` | - | - |

## Form Components

### Input Styling

```vue
<template>
    <div class="space-y-1.5">
        <Label for="name" class="text-sm font-medium">
            Name
        </Label>
        <Input
            id="name"
            v-model="form.name"
            class="h-9"
            placeholder="Enter agent name"
        />
        <p v-if="errors.name" class="text-xs text-destructive">
            {{ errors.name }}
        </p>
    </div>
</template>
```

### Form Layout

```vue
<template>
    <!-- Compact form layout -->
    <form class="space-y-4">
        <!-- Two columns on larger screens -->
        <div class="grid gap-4 sm:grid-cols-2">
            <FormField label="First Name" ... />
            <FormField label="Last Name" ... />
        </div>

        <!-- Full width -->
        <FormField label="Email" ... />

        <!-- Textarea -->
        <FormField label="Description">
            <Textarea rows="3" ... />
        </FormField>

        <!-- Actions -->
        <div class="flex justify-end gap-2 pt-2">
            <Button variant="outline">Cancel</Button>
            <Button type="submit">Save</Button>
        </div>
    </form>
</template>
```

## Tables

### Compact Table Styling

```vue
<template>
    <Table>
        <TableHeader>
            <TableRow>
                <TableHead class="text-xs uppercase tracking-wide text-muted-foreground">
                    Name
                </TableHead>
                <TableHead class="text-xs uppercase tracking-wide text-muted-foreground">
                    Status
                </TableHead>
                <TableHead class="text-xs uppercase tracking-wide text-muted-foreground text-right">
                    Actions
                </TableHead>
            </TableRow>
        </TableHeader>
        <TableBody>
            <TableRow v-for="item in items" :key="item.id">
                <TableCell class="py-2.5">
                    <div class="font-medium">{{ item.name }}</div>
                    <div class="text-xs text-muted-foreground">{{ item.subtitle }}</div>
                </TableCell>
                <TableCell class="py-2.5">
                    <Badge :variant="item.status">{{ item.status }}</Badge>
                </TableCell>
                <TableCell class="py-2.5 text-right">
                    <DropdownMenu>...</DropdownMenu>
                </TableCell>
            </TableRow>
        </TableBody>
    </Table>
</template>
```

## Badges

### Badge Variants

```vue
<template>
    <!-- Status badges -->
    <Badge variant="default">Active</Badge>
    <Badge variant="secondary">Pending</Badge>
    <Badge variant="outline">Draft</Badge>
    <Badge variant="destructive">Failed</Badge>

    <!-- Subtle badges (background with color tint) -->
    <Badge class="bg-success/10 text-success border-0">Completed</Badge>
    <Badge class="bg-warning/10 text-warning border-0">Processing</Badge>
    <Badge class="bg-info/10 text-info border-0">New</Badge>
</template>
```

## Transitions

### Standard Transitions

```css
/* Add to app.css */

.transition-default {
    transition-property: color, background-color, border-color, box-shadow, opacity;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 150ms;
}

.transition-transform {
    transition-property: transform;
    transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
    transition-duration: 200ms;
}

.transition-all-smooth {
    transition: all 200ms cubic-bezier(0.4, 0, 0.2, 1);
}
```

### Common Patterns

```vue
<template>
    <!-- Hover lift -->
    <div class="hover:-translate-y-0.5 transition-transform">
        Card content
    </div>

    <!-- Fade in -->
    <Transition name="fade">
        <div v-if="show">Content</div>
    </Transition>

    <!-- Slide in from right (for sheets) -->
    <Transition name="slide-right">
        <div v-if="show">Sheet content</div>
    </Transition>
</template>

<style>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}
.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.slide-right-enter-active,
.slide-right-leave-active {
    transition: transform 200ms ease;
}
.slide-right-enter-from,
.slide-right-leave-to {
    transform: translateX(100%);
}
</style>
```
