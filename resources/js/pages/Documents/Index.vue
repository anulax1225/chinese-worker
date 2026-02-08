<script setup lang="ts">
import { router, WhenVisible, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { AppLayout } from '@/layouts';
import { destroy } from '@/actions/App/Http/Controllers/Api/V1/DocumentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
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
import {
    Plus,
    Search,
    MoreHorizontal,
    Eye,
    Trash2,
    Filter,
    X,
    Loader2,
    FileText,
    RefreshCw,
} from 'lucide-vue-next';
import type { Document, DocumentStatus, BreadcrumbItem } from '@/types';

interface Filters {
    status: string | null;
    search: string | null;
}

const props = defineProps<{
    documents: Document[];
    nextCursor: string | null;
    filters: Filters;
    breadcrumbs: BreadcrumbItem[];
}>();

const search = ref(props.filters.search || '');
const status = ref(props.filters.status || 'all');
const showFilters = ref(false);
const deleting = ref<number | null>(null);

const hasActiveFilters = computed(() => {
    return props.filters.search || props.filters.status;
});

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
    return params;
});

const applyFilters = () => {
    router.get('/documents', {
        search: search.value || undefined,
        status: status.value === 'all' ? undefined : status.value,
    }, {
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    search.value = '';
    status.value = 'all';
    router.get('/documents', {}, {
        preserveState: false,
        replace: true,
    });
};

const deleteDocument = async (doc: Document, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (!confirm('Are you sure you want to delete this document?')) {
        return;
    }

    deleting.value = doc.id;
    try {
        const response = await fetch(destroy.url(doc.id), {
            method: 'DELETE',
            headers: {
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });

        if (response.ok) {
            router.reload({ only: ['documents', 'nextCursor'] });
        }
    } finally {
        deleting.value = null;
    }
};

const getStatusBadgeClass = (status: DocumentStatus) => {
    const colors: Record<DocumentStatus, string> = {
        pending: 'bg-yellow-500/10 text-yellow-600 border-yellow-500/20',
        extracting: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        cleaning: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        normalizing: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        chunking: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        ready: 'bg-green-500/10 text-green-600 border-green-500/20',
        failed: 'bg-red-500/10 text-red-600 border-red-500/20',
    };
    return colors[status] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};

const getSourceTypeBadgeClass = (sourceType: string) => {
    const colors: Record<string, string> = {
        upload: 'bg-purple-500/10 text-purple-600 border-purple-500/20',
        url: 'bg-indigo-500/10 text-indigo-600 border-indigo-500/20',
        paste: 'bg-orange-500/10 text-orange-600 border-orange-500/20',
    };
    return colors[sourceType] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};

const isProcessing = (status: DocumentStatus) => {
    return ['extracting', 'cleaning', 'normalizing', 'chunking'].includes(status);
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
    });
};

watch(() => props.filters, (newFilters) => {
    search.value = newFilters.search || '';
    status.value = newFilters.status || 'all';
}, { immediate: true });
</script>

<template>
    <AppLayout title="Documents">
        <div class="max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">Documents</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ documents.length }} loaded
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
                    <Link href="/documents/create">
                        <Button>
                            <Plus class="h-4 w-4 mr-2" />
                            Add Document
                        </Button>
                    </Link>
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
                            placeholder="Search documents..."
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
                            <SelectItem value="pending">Pending</SelectItem>
                            <SelectItem value="ready">Ready</SelectItem>
                            <SelectItem value="failed">Failed</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="documents.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <FileText class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No documents yet</h3>
                <p class="text-muted-foreground mb-6">
                    Upload your first document to get started.
                </p>
                <Link href="/documents/create">
                    <Button>
                        <Plus class="h-4 w-4 mr-2" />
                        Add Document
                    </Button>
                </Link>
            </div>

            <!-- Document Cards -->
            <div v-else class="space-y-6">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <Link
                        v-for="doc in documents"
                        :key="doc.id"
                        :href="`/documents/${doc.id}`"
                        class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                    >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="absolute top-3 right-3 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                    @click.prevent.stop
                                >
                                    <MoreHorizontal class="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem as-child>
                                    <Link :href="`/documents/${doc.id}`" class="cursor-pointer">
                                        <Eye class="mr-2 h-4 w-4" />
                                        View
                                    </Link>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @click="deleteDocument(doc, $event)"
                                    class="cursor-pointer text-destructive"
                                    :disabled="deleting === doc.id"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    {{ deleting === doc.id ? 'Deleting...' : 'Delete' }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <div class="pr-6">
                            <!-- Header with icon -->
                            <div class="flex items-center gap-3 mb-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-muted">
                                    <RefreshCw v-if="isProcessing(doc.status)" class="h-4 w-4 text-muted-foreground animate-spin" />
                                    <FileText v-else class="h-4 w-4 text-muted-foreground" />
                                </div>
                                <h3 class="font-medium text-sm truncate flex-1">
                                    {{ doc.title || 'Untitled' }}
                                </h3>
                            </div>

                            <!-- Status and source type badges -->
                            <div class="flex items-center gap-2 mb-2 flex-wrap">
                                <Badge variant="outline" :class="['text-xs font-normal', getStatusBadgeClass(doc.status)]">
                                    {{ doc.status }}
                                </Badge>
                                <Badge variant="outline" :class="['text-xs font-normal', getSourceTypeBadgeClass(doc.source_type)]">
                                    {{ doc.source_type }}
                                </Badge>
                            </div>

                            <!-- Error message if failed -->
                            <p v-if="doc.status === 'failed' && doc.error_message" class="text-xs text-destructive mb-2 line-clamp-2">
                                {{ doc.error_message }}
                            </p>

                            <!-- Metadata -->
                            <div class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
                                <span>{{ formatFileSize(doc.file_size) }}</span>
                                <span>·</span>
                                <span>{{ doc.mime_type }}</span>
                                <span>·</span>
                                <span>{{ formatDate(doc.created_at) }}</span>
                            </div>
                        </div>
                    </Link>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="nextCursor"
                    :data="['documents', 'nextCursor']"
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
                <div v-else-if="documents.length > 0" class="text-center py-6 text-sm text-muted-foreground">
                    You've reached the end
                </div>
            </div>
        </div>
    </AppLayout>
</template>
