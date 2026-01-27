<script setup lang="ts">
import { ref, watch, onMounted, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/layouts/AuthenticatedLayout.vue';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog as UploadDialog,
    DialogContent as UploadDialogContent,
    DialogDescription as UploadDialogDescription,
    DialogFooter as UploadDialogFooter,
    DialogHeader as UploadDialogHeader,
    DialogTitle as UploadDialogTitle,
} from '@/components/ui/dialog';
import { Search, MoreHorizontal, Download, Trash2, Upload, FileIcon, Loader2 } from 'lucide-vue-next';
import { formatDistanceToNow } from 'date-fns';
import type { File as FileModel, FileType } from '@/sdk/types';
import type { Auth } from '@/types/auth';
import { useDebounceFn } from '@vueuse/core';
import { listFiles, uploadFile as uploadFileApi, deleteFile as deleteFileApi, downloadAndSaveFile } from '@/sdk/files';

interface Props {
    auth: Auth;
}

const props = defineProps<Props>();

// State
const loading = ref(true);
const error = ref<string | null>(null);
const files = ref<FileModel[]>([]);
const meta = ref({
    current_page: 1,
    per_page: 15,
    total: 0,
    last_page: 1,
    from: 1,
    to: 0,
});

// Filters
const search = ref('');
const type = ref('all');
const currentPage = ref(1);

// Upload state
const uploadDialogOpen = ref(false);
const uploadFile = ref<File | null>(null);
const uploadType = ref<FileType>('input');
const uploading = ref(false);
const dragActive = ref(false);

// Computed
const hasFiles = computed(() => files.value.length > 0);

const getTypeVariant = (type: string) => {
    switch (type) {
        case 'input':
            return 'default';
        case 'output':
            return 'secondary';
        case 'temp':
            return 'outline';
        default:
            return 'outline';
    }
};

const formatDate = (date: string) => {
    return formatDistanceToNow(new Date(date), { addSuffix: true });
};

const formatSize = (bytes: number) => {
    const units = ['B', 'KB', 'MB', 'GB'];
    let size = bytes;
    let unitIndex = 0;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return `${size.toFixed(1)} ${units[unitIndex]}`;
};

const getFileName = (path: string) => {
    return path.split('/').pop() || path;
};

// Fetch files from API
const fetchFiles = async () => {
    loading.value = true;
    error.value = null;

    try {
        const params: Record<string, unknown> = {
            page: currentPage.value,
            per_page: 15,
        };

        if (search.value) {
            params.search = search.value;
        }

        if (type.value && type.value !== 'all') {
            params.type = type.value as FileType;
        }

        const response = await listFiles(params);
        files.value = response.data;
        meta.value = {
            current_page: response.meta.current_page,
            per_page: response.meta.per_page,
            total: response.meta.total,
            last_page: response.meta.last_page,
            from: response.meta.from ?? ((response.meta.current_page - 1) * response.meta.per_page + 1),
            to: response.meta.to ?? Math.min(response.meta.current_page * response.meta.per_page, response.meta.total),
        };
    } catch (e) {
        error.value = e instanceof Error ? e.message : 'Failed to load files';
        console.error('Failed to fetch files:', e);
    } finally {
        loading.value = false;
    }
};

// Debounced filter application
const applyFilters = useDebounceFn(() => {
    currentPage.value = 1;
    fetchFiles();
}, 300);

// Watch for filter changes
watch([search, type], () => {
    applyFilters();
});

const handleDrop = (e: DragEvent) => {
    e.preventDefault();
    dragActive.value = false;
    if (e.dataTransfer?.files && e.dataTransfer.files.length > 0) {
        uploadFile.value = e.dataTransfer.files[0];
    }
};

const handleDragOver = (e: DragEvent) => {
    e.preventDefault();
    dragActive.value = true;
};

const handleDragLeave = () => {
    dragActive.value = false;
};

const handleFileSelect = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files && target.files.length > 0) {
        uploadFile.value = target.files[0];
    }
};

const submitUpload = async () => {
    if (!uploadFile.value) return;

    uploading.value = true;

    try {
        await uploadFileApi(uploadFile.value, uploadType.value);
        await fetchFiles();
        uploadDialogOpen.value = false;
        uploadFile.value = null;
        uploadType.value = 'input';
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to upload file');
    } finally {
        uploading.value = false;
    }
};

const handleDeleteFile = async (file: FileModel) => {
    if (!confirm(`Are you sure you want to delete "${getFileName(file.path)}"?`)) {
        return;
    }

    try {
        await deleteFileApi(file.id);
        await fetchFiles();
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to delete file');
    }
};

const handleDownloadFile = async (file: FileModel) => {
    try {
        await downloadAndSaveFile(file.id, getFileName(file.path));
    } catch (e) {
        alert(e instanceof Error ? e.message : 'Failed to download file');
    }
};

// Pagination
const goToPage = (page: number) => {
    currentPage.value = page;
    fetchFiles();
};

// Initial load
onMounted(() => {
    fetchFiles();
});
</script>

<template>
    <AuthenticatedLayout title="Files" :auth="auth">
        <div class="space-y-6">
            <!-- Page Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Files</h1>
                    <p class="text-muted-foreground">Manage your uploaded files</p>
                </div>
                <UploadDialog v-model:open="uploadDialogOpen">
                    <Button @click="uploadDialogOpen = true">
                        <Upload class="mr-2 h-4 w-4" />
                        Upload File
                    </Button>
                    <UploadDialogContent>
                        <UploadDialogHeader>
                            <UploadDialogTitle>Upload File</UploadDialogTitle>
                            <UploadDialogDescription>
                                Drag and drop a file or click to browse
                            </UploadDialogDescription>
                        </UploadDialogHeader>
                        <div class="space-y-4 py-4">
                            <div
                                class="flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors"
                                :class="[
                                    dragActive ? 'border-primary bg-primary/5' : 'border-muted-foreground/25',
                                    uploadFile ? 'bg-muted/50' : '',
                                ]"
                                @drop="handleDrop"
                                @dragover="handleDragOver"
                                @dragleave="handleDragLeave"
                            >
                                <FileIcon class="mb-4 h-10 w-10 text-muted-foreground" />
                                <div v-if="uploadFile" class="text-center">
                                    <p class="font-medium">{{ uploadFile.name }}</p>
                                    <p class="text-sm text-muted-foreground">{{ formatSize(uploadFile.size) }}</p>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="mt-2"
                                        @click="uploadFile = null"
                                    >
                                        Remove
                                    </Button>
                                </div>
                                <div v-else class="text-center">
                                    <p class="text-muted-foreground">Drag and drop a file here, or</p>
                                    <Label
                                        for="file-upload"
                                        class="mt-2 inline-block cursor-pointer text-primary hover:underline"
                                    >
                                        click to browse
                                    </Label>
                                    <Input
                                        id="file-upload"
                                        type="file"
                                        class="hidden"
                                        @change="handleFileSelect"
                                    />
                                </div>
                            </div>

                            <div class="space-y-2">
                                <Label for="upload-type">File Type</Label>
                                <Select v-model="uploadType">
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
                        </div>
                        <UploadDialogFooter>
                            <Button variant="outline" @click="uploadDialogOpen = false">
                                Cancel
                            </Button>
                            <Button @click="submitUpload" :disabled="!uploadFile || uploading">
                                <Loader2 v-if="uploading" class="mr-2 h-4 w-4 animate-spin" />
                                <Upload v-else class="mr-2 h-4 w-4" />
                                {{ uploading ? 'Uploading...' : 'Upload' }}
                            </Button>
                        </UploadDialogFooter>
                    </UploadDialogContent>
                </UploadDialog>
            </div>

            <!-- Filters -->
            <Card>
                <CardHeader>
                    <CardTitle>Filters</CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="flex gap-4">
                        <div class="relative flex-1">
                            <Search class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                v-model="search"
                                placeholder="Search files..."
                                class="pl-10"
                            />
                        </div>
                        <Select v-model="type">
                            <SelectTrigger class="w-[180px]">
                                <SelectValue placeholder="Filter by type" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Types</SelectItem>
                                <SelectItem value="input">Input</SelectItem>
                                <SelectItem value="output">Output</SelectItem>
                                <SelectItem value="temp">Temporary</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </CardContent>
            </Card>

            <!-- Files Table -->
            <Card>
                <CardHeader>
                    <CardTitle>Your Files</CardTitle>
                    <CardDescription v-if="!loading">
                        {{ meta.total }} file{{ meta.total !== 1 ? 's' : '' }} found
                    </CardDescription>
                    <CardDescription v-else>
                        Loading...
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <!-- Loading State -->
                    <div v-if="loading" class="space-y-4">
                        <div v-for="i in 5" :key="i" class="flex items-center space-x-4">
                            <Skeleton class="h-12 w-full" />
                        </div>
                    </div>

                    <!-- Error State -->
                    <div v-else-if="error" class="text-center py-8 text-destructive">
                        {{ error }}
                        <Button variant="link" @click="fetchFiles">Try again</Button>
                    </div>

                    <!-- Empty State -->
                    <div v-else-if="!hasFiles" class="text-center py-8 text-muted-foreground">
                        No files found. Upload your first file to get started.
                    </div>

                    <!-- Files Table -->
                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Size</TableHead>
                                <TableHead>MIME Type</TableHead>
                                <TableHead>Uploaded</TableHead>
                                <TableHead class="text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="file in files" :key="file.id">
                                <TableCell class="font-medium">
                                    <div class="flex items-center gap-2">
                                        <FileIcon class="h-4 w-4 text-muted-foreground" />
                                        {{ getFileName(file.path) }}
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :variant="getTypeVariant(file.type)">
                                        {{ file.type }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatSize(file.size) }}</TableCell>
                                <TableCell class="text-muted-foreground">{{ file.mime_type }}</TableCell>
                                <TableCell>{{ formatDate(file.created_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button variant="ghost" size="sm">
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem @click="handleDownloadFile(file)">
                                                <Download class="mr-2 h-4 w-4" />
                                                Download
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive"
                                                @click="handleDeleteFile(file)"
                                            >
                                                <Trash2 class="mr-2 h-4 w-4" />
                                                Delete
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <!-- Pagination -->
                    <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between">
                        <p class="text-sm text-muted-foreground">
                            Showing {{ meta.from }} to {{ meta.to }} of {{ meta.total }} results
                        </p>
                        <div class="flex gap-2">
                            <Button
                                v-for="page in meta.last_page"
                                :key="page"
                                :variant="page === meta.current_page ? 'default' : 'outline'"
                                size="sm"
                                @click="goToPage(page)"
                            >
                                {{ page }}
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AuthenticatedLayout>
</template>
