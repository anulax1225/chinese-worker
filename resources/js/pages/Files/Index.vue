<script setup lang="ts">
import { router, useForm, WhenVisible } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { AppLayout } from '@/layouts';
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
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Plus,
    Search,
    MoreHorizontal,
    Download,
    Trash2,
    Upload,
    Filter,
    X,
    Loader2,
    FileText,
    Image,
    File as FileIcon,
} from 'lucide-vue-next';
import type { File } from '@/types';

interface Filters {
    type: string | null;
    search: string | null;
}

const props = defineProps<{
    files: File[];
    nextCursor: string | null;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const type = ref(props.filters.type || 'all');
const showFilters = ref(false);
const uploadDialogOpen = ref(false);
const uploadProgress = ref(0);
const isUploading = ref(false);

const uploadForm = useForm({
    file: null as globalThis.File | null,
    type: 'input' as 'input' | 'output' | 'temp',
});

const hasActiveFilters = computed(() => {
    return props.filters.search || props.filters.type;
});

const loadMoreParams = computed(() => {
    const params: Record<string, string> = {};
    if (props.nextCursor) {
        params.cursor = props.nextCursor;
    }
    if (props.filters.search) {
        params.search = props.filters.search;
    }
    if (props.filters.type) {
        params.type = props.filters.type;
    }
    return params;
});

const applyFilters = () => {
    router.get('/files', {
        search: search.value || undefined,
        type: type.value === 'all' ? undefined : type.value,
    }, {
        preserveState: false,
        replace: true,
    });
};

const clearFilters = () => {
    search.value = '';
    type.value = 'all';
    router.get('/files', {}, {
        preserveState: false,
        replace: true,
    });
};

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        uploadForm.file = target.files[0];
    }
};

const uploadFile = () => {
    if (!uploadForm.file) return;

    isUploading.value = true;
    uploadProgress.value = 0;

    uploadForm.post('/files', {
        forceFormData: true,
        onProgress: (progress) => {
            uploadProgress.value = progress.percentage || 0;
        },
        onSuccess: () => {
            uploadDialogOpen.value = false;
            uploadForm.reset();
            uploadProgress.value = 0;
        },
        onFinish: () => {
            isUploading.value = false;
        },
    });
};

const deleteFile = (file: File, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    if (confirm('Are you sure you want to delete this file?')) {
        router.delete(`/files/${file.id}`, {
            preserveState: false,
        });
    }
};

const getTypeBadgeClass = (type: string) => {
    const colors: Record<string, string> = {
        input: 'bg-blue-500/10 text-blue-600 border-blue-500/20',
        output: 'bg-green-500/10 text-green-600 border-green-500/20',
        temp: 'bg-gray-500/10 text-gray-600 border-gray-500/20',
    };
    return colors[type] || 'bg-gray-500/10 text-gray-600 border-gray-500/20';
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

const getFileIcon = (mimeType: string) => {
    if (mimeType.startsWith('image/')) return Image;
    if (mimeType.startsWith('text/')) return FileText;
    return FileIcon;
};

const getFileName = (path: string) => {
    return path.split('/').pop() || path;
};

watch(() => props.filters, (newFilters) => {
    search.value = newFilters.search || '';
    type.value = newFilters.type || 'all';
}, { immediate: true });
</script>

<template>
    <AppLayout title="Files">
        <div class="max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <h1 class="text-2xl font-semibold">Files</h1>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ files.length }} loaded
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
                    <Dialog v-model:open="uploadDialogOpen">
                        <DialogTrigger as-child>
                            <Button>
                                <Upload class="h-4 w-4 mr-2" />
                                Upload File
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>Upload File</DialogTitle>
                                <DialogDescription>Upload a new file to your storage</DialogDescription>
                            </DialogHeader>
                            <div class="space-y-4 py-4">
                                <div class="space-y-2">
                                    <Label for="file">File</Label>
                                    <Input
                                        id="file"
                                        type="file"
                                        @change="handleFileSelect"
                                        :disabled="isUploading"
                                    />
                                    <p v-if="uploadForm.errors.file" class="text-sm text-destructive">
                                        {{ uploadForm.errors.file }}
                                    </p>
                                </div>
                                <div class="space-y-2">
                                    <Label for="type">Type</Label>
                                    <Select v-model="uploadForm.type" :disabled="isUploading">
                                        <SelectTrigger>
                                            <SelectValue placeholder="Select type" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="input">Input</SelectItem>
                                            <SelectItem value="output">Output</SelectItem>
                                            <SelectItem value="temp">Temporary</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </div>
                                <Progress v-if="isUploading" :model-value="uploadProgress" class="h-2" />
                            </div>
                            <DialogFooter>
                                <Button variant="outline" @click="uploadDialogOpen = false" :disabled="isUploading">
                                    Cancel
                                </Button>
                                <Button @click="uploadFile" :disabled="!uploadForm.file || isUploading">
                                    {{ isUploading ? `Uploading... ${uploadProgress}%` : 'Upload' }}
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
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
                            placeholder="Search files..."
                            class="pl-8"
                            @keyup.enter="applyFilters"
                        />
                    </div>
                    <Select v-model="type" @update:model-value="applyFilters">
                        <SelectTrigger class="w-35">
                            <SelectValue placeholder="Type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All Types</SelectItem>
                            <SelectItem value="input">Input</SelectItem>
                            <SelectItem value="output">Output</SelectItem>
                            <SelectItem value="temp">Temporary</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Empty State -->
            <div
                v-if="files.length === 0"
                class="text-center py-16"
            >
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-muted mb-4">
                    <FileIcon class="h-8 w-8 text-muted-foreground" />
                </div>
                <h3 class="text-lg font-medium mb-2">No files yet</h3>
                <p class="text-muted-foreground mb-6">
                    Upload your first file to get started.
                </p>
                <Button @click="uploadDialogOpen = true">
                    <Upload class="h-4 w-4 mr-2" />
                    Upload File
                </Button>
            </div>

            <!-- File Cards -->
            <div v-else class="space-y-6">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    <div
                        v-for="file in files"
                        :key="file.id"
                        class="group relative bg-card border rounded-xl p-4 hover:border-primary/50 hover:shadow-md transition-all"
                    >
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="absolute top-3 right-3 h-7 w-7 opacity-0 group-hover:opacity-100 transition-opacity"
                                >
                                    <MoreHorizontal class="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem as-child>
                                    <a :href="`/files/${file.id}`" download class="cursor-pointer">
                                        <Download class="mr-2 h-4 w-4" />
                                        Download
                                    </a>
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    @click="deleteFile(file, $event)"
                                    class="cursor-pointer text-destructive"
                                >
                                    <Trash2 class="mr-2 h-4 w-4" />
                                    Delete
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>

                        <div class="pr-6">
                            <!-- Header with icon -->
                            <div class="flex items-center gap-3 mb-3">
                                <div class="flex items-center justify-center w-9 h-9 rounded-lg bg-muted">
                                    <component :is="getFileIcon(file.mime_type)" class="h-4 w-4 text-muted-foreground" />
                                </div>
                                <h3 class="font-medium text-sm truncate flex-1">
                                    {{ getFileName(file.path) }}
                                </h3>
                            </div>

                            <!-- Metadata -->
                            <div class="flex items-center gap-2 text-xs text-muted-foreground flex-wrap">
                                <Badge variant="outline" :class="['text-xs font-normal', getTypeBadgeClass(file.type)]">
                                    {{ file.type }}
                                </Badge>
                                <span>{{ formatFileSize(file.size) }}</span>
                                <span>·</span>
                                <span>{{ formatDate(file.created_at) }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Infinite Scroll Trigger -->
                <WhenVisible
                    v-if="nextCursor"
                    :data="['files', 'nextCursor']"
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
                <div v-else-if="files.length > 0" class="text-center py-6 text-sm text-muted-foreground">
                    You've reached the end
                </div>
            </div>
        </div>
    </AppLayout>
</template>
