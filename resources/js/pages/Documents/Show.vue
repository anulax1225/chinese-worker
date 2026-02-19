<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import {
    destroy,
    reprocess,
    chunks as fetchChunks,
    preview as fetchPreview,
} from '@/actions/App/Http/Controllers/Api/V1/DocumentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    ArrowLeft,
    RefreshCw,
    Trash2,
    Loader2,
    FileText,
    ChevronDown,
    Clock,
    HardDrive,
    Layers,
    Hash,
    AlertCircle,
    CheckCircle2,
    Search,
    X,
} from 'lucide-vue-next';
import type { Document, DocumentStage, BreadcrumbItem } from '@/types';

interface DocumentChunk {
    id: number;
    chunk_index: number;
    content: string;
    token_count: number;
    section_title: string | null;
}

const props = defineProps<{
    document: Document;
    chunksCount: number;
    totalTokens: number;
    breadcrumbs: BreadcrumbItem[];
}>();

const activeTab = ref('overview');
const isReprocessing = ref(false);
const isDeleting = ref(false);
const openStages = ref<Set<string>>(new Set());

// Chunks data (lazy loaded)
const chunksData = ref<DocumentChunk[]>([]);
const chunksLoading = ref(false);
const chunksPage = ref(1);
const chunksHasMore = ref(false);
const chunkSearch = ref('');

const filteredChunks = computed(() => {
    const query = chunkSearch.value.trim().toLowerCase();
    if (!query) return chunksData.value;
    return chunksData.value.filter(
        (chunk) =>
            chunk.content.toLowerCase().includes(query) ||
            chunk.section_title?.toLowerCase().includes(query)
    );
});

// Preview data (lazy loaded)
const previewData = ref<{
    original_preview: string | null;
    cleaned_preview: string | null;
    sample_chunks: DocumentChunk[];
} | null>(null);
const previewLoading = ref(false);

// Polling for processing status
let pollInterval: ReturnType<typeof setInterval> | null = null;

const isProcessing = computed(() => {
    return ['pending', 'extracting', 'cleaning', 'normalizing', 'chunking'].includes(
        props.document.status
    );
});

const canReprocess = computed(() => {
    return ['ready', 'failed'].includes(props.document.status);
});

const getStatusBadgeClass = (status: string) => {
    const colors: Record<string, string> = {
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

const getStageBadgeClass = (stage: string) => {
    const colors: Record<string, string> = {
        extracted: 'bg-cyan-500/10 text-cyan-600 border-cyan-500/20',
        cleaned: 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
        normalized: 'bg-violet-500/10 text-violet-600 border-violet-500/20',
        chunked: 'bg-amber-500/10 text-amber-600 border-amber-500/20',
    };
    return colors[stage] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};

const formatDate = (date: string | null) => {
    if (!date) return 'N/A';
    return new Date(date).toLocaleString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

const toggleStage = (stageId: string) => {
    if (openStages.value.has(stageId)) {
        openStages.value.delete(stageId);
    } else {
        openStages.value.add(stageId);
    }
};

const handleReprocess = async () => {
    isReprocessing.value = true;
    try {
        await fetch(reprocess.url(props.document.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });
        router.reload();
    } finally {
        isReprocessing.value = false;
    }
};

const handleDelete = async () => {
    isDeleting.value = true;
    try {
        const response = await fetch(destroy.url(props.document.id), {
            method: 'DELETE',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });
        if (response.ok) {
            router.visit('/documents');
        }
    } finally {
        isDeleting.value = false;
    }
};

const loadChunks = async (page = 1) => {
    if (chunksLoading.value) return;
    chunksLoading.value = true;
    try {
        const response = await fetch(
            fetchChunks.url(props.document.id, { query: { page: page.toString(), per_page: '20' } }),
            {
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                    ),
                },
            }
        );
        const data = await response.json();
        if (page === 1) {
            chunksData.value = data.data;
        } else {
            chunksData.value = [...chunksData.value, ...data.data];
        }
        chunksPage.value = page;
        chunksHasMore.value = data.meta?.current_page < data.meta?.last_page;
    } finally {
        chunksLoading.value = false;
    }
};

const loadPreview = async () => {
    if (previewLoading.value || previewData.value) return;
    previewLoading.value = true;
    try {
        const response = await fetch(fetchPreview.url(props.document.id), {
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
        });
        previewData.value = await response.json();
    } finally {
        previewLoading.value = false;
    }
};

const handleTabChange = (tab: string) => {
    activeTab.value = tab;
    if (tab === 'chunks' && chunksData.value.length === 0) {
        loadChunks();
    } else if (tab === 'preview' && !previewData.value) {
        loadPreview();
    }
    if (tab !== 'chunks') {
        chunkSearch.value = '';
    }
};

// Start polling if document is processing
onMounted(() => {
    if (isProcessing.value) {
        pollInterval = setInterval(() => {
            router.reload({ only: ['document', 'chunksCount', 'totalTokens'] });
        }, 3000);
    }
});

onUnmounted(() => {
    if (pollInterval) {
        clearInterval(pollInterval);
    }
});
</script>

<template>
    <AppLayout :title="document.title || 'Document'">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center gap-4 mb-4">
                    <Link href="/documents">
                        <Button variant="ghost" size="icon" class="h-8 w-8">
                            <ArrowLeft class="h-4 w-4" />
                        </Button>
                    </Link>
                    <div class="flex-1">
                        <div class="flex items-center gap-3">
                            <h1 class="text-2xl font-semibold">
                                {{ document.title || 'Untitled Document' }}
                            </h1>
                            <Badge
                                variant="outline"
                                :class="['font-normal', getStatusBadgeClass(document.status)]"
                            >
                                <RefreshCw
                                    v-if="isProcessing"
                                    class="h-3 w-3 mr-1 animate-spin"
                                />
                                <CheckCircle2
                                    v-else-if="document.status === 'ready'"
                                    class="h-3 w-3 mr-1"
                                />
                                <AlertCircle
                                    v-else-if="document.status === 'failed'"
                                    class="h-3 w-3 mr-1"
                                />
                                {{ document.status }}
                            </Badge>
                        </div>
                        <p class="text-sm text-muted-foreground mt-1">
                            Document #{{ document.id }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="canReprocess"
                            variant="outline"
                            @click="handleReprocess"
                            :disabled="isReprocessing"
                        >
                            <RefreshCw
                                :class="[
                                    'h-4 w-4 mr-2',
                                    isReprocessing ? 'animate-spin' : '',
                                ]"
                            />
                            {{ isReprocessing ? 'Reprocessing...' : 'Reprocess' }}
                        </Button>
                        <AlertDialog>
                            <AlertDialogTrigger as-child>
                                <Button variant="destructive" :disabled="isDeleting">
                                    <Trash2 class="h-4 w-4 mr-2" />
                                    Delete
                                </Button>
                            </AlertDialogTrigger>
                            <AlertDialogContent>
                                <AlertDialogHeader>
                                    <AlertDialogTitle>Delete Document</AlertDialogTitle>
                                    <AlertDialogDescription>
                                        Are you sure you want to delete this document? This action
                                        cannot be undone and will remove all associated data.
                                    </AlertDialogDescription>
                                </AlertDialogHeader>
                                <AlertDialogFooter>
                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                    <AlertDialogAction
                                        @click="handleDelete"
                                        class="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                    >
                                        <Loader2
                                            v-if="isDeleting"
                                            class="h-4 w-4 mr-2 animate-spin"
                                        />
                                        Delete
                                    </AlertDialogAction>
                                </AlertDialogFooter>
                            </AlertDialogContent>
                        </AlertDialog>
                    </div>
                </div>

                <!-- Error Message -->
                <div
                    v-if="document.status === 'failed' && document.error_message"
                    class="p-4 rounded-lg bg-destructive/10 border border-destructive/20 text-destructive"
                >
                    <div class="flex items-start gap-3">
                        <AlertCircle class="h-5 w-5 flex-shrink-0 mt-0.5" />
                        <div>
                            <p class="font-medium">Processing Failed</p>
                            <p class="text-sm mt-1">{{ document.error_message }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Content Tabs -->
            <Tabs :model-value="activeTab" @update:model-value="handleTabChange" class="space-y-6">
                <TabsList class="grid w-full grid-cols-4">
                    <TabsTrigger value="overview">Overview</TabsTrigger>
                    <TabsTrigger value="stages">
                        Stages
                        <Badge variant="secondary" class="ml-2 h-5 px-1.5 text-xs">
                            {{ document.stages?.length || 0 }}
                        </Badge>
                    </TabsTrigger>
                    <TabsTrigger value="chunks">
                        Chunks
                        <Badge variant="secondary" class="ml-2 h-5 px-1.5 text-xs">
                            {{ chunksCount }}
                        </Badge>
                    </TabsTrigger>
                    <TabsTrigger value="preview">Preview</TabsTrigger>
                </TabsList>

                <!-- Overview Tab -->
                <TabsContent value="overview" class="space-y-4">
                    <div class="grid gap-4 md:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Document Info</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground">Source Type</span>
                                    <Badge
                                        variant="outline"
                                        :class="[
                                            'font-normal',
                                            getSourceTypeBadgeClass(document.source_type),
                                        ]"
                                    >
                                        {{ document.source_type }}
                                    </Badge>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground">MIME Type</span>
                                    <span class="text-sm font-mono">{{ document.mime_type }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground">File Size</span>
                                    <span class="text-sm">{{
                                        formatFileSize(document.file_size)
                                    }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground">Source Path</span>
                                    <span class="text-sm font-mono truncate max-w-48" :title="document.source_path">
                                        {{ document.source_path }}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Processing Stats</CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground flex items-center gap-2">
                                        <Layers class="h-4 w-4" />
                                        Chunks
                                    </span>
                                    <span class="text-sm font-medium">{{ chunksCount }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground flex items-center gap-2">
                                        <Hash class="h-4 w-4" />
                                        Total Tokens
                                    </span>
                                    <span class="text-sm font-medium">{{
                                        totalTokens.toLocaleString()
                                    }}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground flex items-center gap-2">
                                        <HardDrive class="h-4 w-4" />
                                        Word Count
                                    </span>
                                    <span class="text-sm font-medium">
                                        {{ document.metadata?.word_count?.toLocaleString() || 'N/A' }}
                                    </span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-sm text-muted-foreground flex items-center gap-2">
                                        <FileText class="h-4 w-4" />
                                        Character Count
                                    </span>
                                    <span class="text-sm font-medium">
                                        {{ document.metadata?.character_count?.toLocaleString() || 'N/A' }}
                                    </span>
                                </div>
                            </CardContent>
                        </Card>

                        <Card class="md:col-span-2">
                            <CardHeader>
                                <CardTitle class="text-base">Timestamps</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-muted-foreground flex items-center gap-2">
                                            <Clock class="h-4 w-4" />
                                            Created
                                        </span>
                                        <span class="text-sm">{{
                                            formatDate(document.created_at)
                                        }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-muted-foreground flex items-center gap-2">
                                            <Clock class="h-4 w-4" />
                                            Updated
                                        </span>
                                        <span class="text-sm">{{
                                            formatDate(document.updated_at)
                                        }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-muted-foreground flex items-center gap-2">
                                            <Clock class="h-4 w-4" />
                                            Processing Started
                                        </span>
                                        <span class="text-sm">{{
                                            formatDate(document.processing_started_at)
                                        }}</span>
                                    </div>
                                    <div class="flex items-center justify-between">
                                        <span class="text-sm text-muted-foreground flex items-center gap-2">
                                            <Clock class="h-4 w-4" />
                                            Processing Completed
                                        </span>
                                        <span class="text-sm">{{
                                            formatDate(document.processing_completed_at)
                                        }}</span>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </TabsContent>

                <!-- Stages Tab -->
                <TabsContent value="stages" class="space-y-4">
                    <div v-if="!document.stages?.length" class="text-center py-12">
                        <div
                            class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-muted mb-4"
                        >
                            <Layers class="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p class="text-muted-foreground">No processing stages yet</p>
                    </div>

                    <div v-else class="space-y-3">
                        <Collapsible
                            v-for="stage in document.stages"
                            :key="stage.id"
                            :open="openStages.has(String(stage.id))"
                            @update:open="toggleStage(String(stage.id))"
                        >
                            <Card>
                                <CollapsibleTrigger as-child>
                                    <CardHeader class="cursor-pointer hover:bg-muted/50 transition-colors">
                                        <div class="flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <Badge
                                                    variant="outline"
                                                    :class="[
                                                        'font-normal capitalize',
                                                        getStageBadgeClass(stage.stage),
                                                    ]"
                                                >
                                                    {{ stage.stage }}
                                                </Badge>
                                                <span class="text-sm text-muted-foreground">
                                                    {{ formatDate(stage.created_at) }}
                                                </span>
                                            </div>
                                            <ChevronDown
                                                :class="[
                                                    'h-4 w-4 text-muted-foreground transition-transform',
                                                    openStages.has(String(stage.id))
                                                        ? 'rotate-180'
                                                        : '',
                                                ]"
                                            />
                                        </div>
                                    </CardHeader>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <CardContent class="pt-0 space-y-4">
                                        <!-- Metadata -->
                                        <div
                                            v-if="stage.metadata && Object.keys(stage.metadata).length"
                                            class="p-3 bg-muted/50 rounded-lg"
                                        >
                                            <p class="text-xs font-medium text-muted-foreground mb-2">
                                                Metadata
                                            </p>
                                            <div class="grid gap-1 text-sm">
                                                <div
                                                    v-for="(value, key) in stage.metadata"
                                                    :key="key"
                                                    class="flex items-center gap-2"
                                                >
                                                    <span class="text-muted-foreground">{{ key }}:</span>
                                                    <span class="font-mono text-xs">
                                                        {{
                                                            typeof value === 'object'
                                                                ? JSON.stringify(value)
                                                                : value
                                                        }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Content Preview -->
                                        <div v-if="stage.content">
                                            <p class="text-xs font-medium text-muted-foreground mb-2">
                                                Content Preview
                                            </p>
                                            <pre
                                                class="p-3 bg-muted/50 rounded-lg text-xs font-mono overflow-x-auto max-h-64 whitespace-pre-wrap"
                                            >{{ stage.content.slice(0, 2000) }}{{ stage.content.length > 2000 ? '...' : '' }}</pre>
                                        </div>
                                    </CardContent>
                                </CollapsibleContent>
                            </Card>
                        </Collapsible>
                    </div>
                </TabsContent>

                <!-- Chunks Tab -->
                <TabsContent value="chunks" class="space-y-4">
                    <div v-if="chunksLoading && chunksData.length === 0" class="text-center py-12">
                        <Loader2 class="h-8 w-8 animate-spin mx-auto text-muted-foreground" />
                        <p class="text-muted-foreground mt-2">Loading chunks...</p>
                    </div>

                    <div v-else-if="chunksCount === 0" class="text-center py-12">
                        <div
                            class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-muted mb-4"
                        >
                            <Layers class="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p class="text-muted-foreground">No chunks generated yet</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div class="flex items-center justify-between gap-4">
                            <div class="relative flex-1 max-w-sm">
                                <Search class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none" />
                                <Input
                                    v-model="chunkSearch"
                                    placeholder="Search chunks..."
                                    class="pl-9 pr-9"
                                />
                                <button
                                    v-if="chunkSearch"
                                    @click="chunkSearch = ''"
                                    class="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                                >
                                    <X class="h-4 w-4" />
                                </button>
                            </div>
                            <div class="flex items-center gap-4 text-sm text-muted-foreground shrink-0">
                                <span v-if="chunkSearch">{{ filteredChunks.length }} of {{ chunksData.length }} shown</span>
                                <span v-else>{{ chunksData.length }} of {{ chunksCount }} loaded</span>
                                <span>{{ totalTokens.toLocaleString() }} tokens</span>
                            </div>
                        </div>

                        <div v-if="chunkSearch && filteredChunks.length === 0" class="text-center py-8">
                            <p class="text-muted-foreground">No chunks match your search</p>
                        </div>

                        <Card v-for="chunk in filteredChunks" :key="chunk.id">
                            <CardHeader class="pb-2">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <Badge variant="secondary">
                                            Chunk #{{ chunk.chunk_index + 1 }}
                                        </Badge>
                                        <span class="text-xs text-muted-foreground">
                                            {{ chunk.token_count }} tokens
                                        </span>
                                    </div>
                                    <span
                                        v-if="chunk.section_title"
                                        class="text-xs text-muted-foreground"
                                    >
                                        {{ chunk.section_title }}
                                    </span>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    class="text-sm font-mono whitespace-pre-wrap text-muted-foreground"
                                >{{ chunk.content }}</pre>
                            </CardContent>
                        </Card>

                        <div v-if="chunksHasMore" class="text-center">
                            <Button
                                variant="outline"
                                @click="loadChunks(chunksPage + 1)"
                                :disabled="chunksLoading"
                            >
                                <Loader2
                                    v-if="chunksLoading"
                                    class="h-4 w-4 mr-2 animate-spin"
                                />
                                Load More
                            </Button>
                        </div>
                    </div>
                </TabsContent>

                <!-- Preview Tab -->
                <TabsContent value="preview" class="space-y-4">
                    <div v-if="previewLoading" class="text-center py-12">
                        <Loader2 class="h-8 w-8 animate-spin mx-auto text-muted-foreground" />
                        <p class="text-muted-foreground mt-2">Loading preview...</p>
                    </div>

                    <div
                        v-else-if="!previewData?.original_preview && !previewData?.cleaned_preview"
                        class="text-center py-12"
                    >
                        <div
                            class="inline-flex items-center justify-center w-12 h-12 rounded-full bg-muted mb-4"
                        >
                            <FileText class="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p class="text-muted-foreground">No preview available</p>
                    </div>

                    <div v-else class="grid gap-4 lg:grid-cols-2">
                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Original (Extracted)</CardTitle>
                                <CardDescription>
                                    Raw text after extraction
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    v-if="previewData?.original_preview"
                                    class="p-3 bg-muted/50 rounded-lg text-xs font-mono overflow-x-auto max-h-96 whitespace-pre-wrap"
                                >{{ previewData.original_preview }}</pre>
                                <p v-else class="text-sm text-muted-foreground italic">
                                    No extracted content
                                </p>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Cleaned</CardTitle>
                                <CardDescription>
                                    Text after cleaning pipeline
                                </CardDescription>
                            </CardHeader>
                            <CardContent>
                                <pre
                                    v-if="previewData?.cleaned_preview"
                                    class="p-3 bg-muted/50 rounded-lg text-xs font-mono overflow-x-auto max-h-96 whitespace-pre-wrap"
                                >{{ previewData.cleaned_preview }}</pre>
                                <p v-else class="text-sm text-muted-foreground italic">
                                    No cleaned content
                                </p>
                            </CardContent>
                        </Card>

                        <Card v-if="previewData?.sample_chunks?.length" class="lg:col-span-2">
                            <CardHeader>
                                <CardTitle class="text-base">Sample Chunks</CardTitle>
                                <CardDescription>
                                    First {{ previewData.sample_chunks.length }} chunks
                                </CardDescription>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div
                                    v-for="chunk in previewData.sample_chunks"
                                    :key="chunk.id"
                                    class="p-3 bg-muted/50 rounded-lg"
                                >
                                    <div class="flex items-center gap-2 mb-2">
                                        <Badge variant="secondary" class="text-xs">
                                            Chunk #{{ chunk.chunk_index + 1 }}
                                        </Badge>
                                        <span class="text-xs text-muted-foreground">
                                            {{ chunk.token_count }} tokens
                                        </span>
                                    </div>
                                    <pre
                                        class="text-xs font-mono whitespace-pre-wrap text-muted-foreground"
                                    >{{ chunk.content }}</pre>
                                </div>
                            </CardContent>
                        </Card>
                    </div>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
