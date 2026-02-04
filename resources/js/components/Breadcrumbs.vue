<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { ChevronRight, Home } from 'lucide-vue-next';
import type { AppPageProps, BreadcrumbItem } from '@/types';

const page = usePage<AppPageProps>();

// Get breadcrumbs from page props or generate from URL
const breadcrumbs = computed<BreadcrumbItem[]>(() => {
    // Check if page provides explicit breadcrumbs
    if (page.props.breadcrumbs && page.props.breadcrumbs.length > 0) {
        return page.props.breadcrumbs;
    }

    // Generate from URL path as fallback
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
            : `#${segment}`;

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
        .replace(/\b\w/g, (c) => c.toUpperCase());
}
</script>

<template>
    <nav v-if="breadcrumbs.length > 0" aria-label="Breadcrumb" class="flex items-center text-sm">
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
            <ChevronRight class="h-4 w-4 mx-2 text-muted-foreground/50 flex-shrink-0" />

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
