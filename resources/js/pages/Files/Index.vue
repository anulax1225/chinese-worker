<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
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
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { Upload, Search, Download, Trash2, FileText, Image, File as FileIcon } from 'lucide-vue-next';
import type { File, PaginatedResponse } from '@/types';

interface Filters {
    type: string | null;
    search: string | null;
}

const props = defineProps<{
    files: PaginatedResponse<File>;
    filters: Filters;
}>();

const search = ref(props.filters.search || '');
const type = ref(props.filters.type || 'all');
const uploadDialogOpen = ref(false);
const uploadProgress = ref(0);
const isUploading = ref(false);

const uploadForm = useForm({
    file: null as globalThis.File | null,
    type: 'input' as 'input' | 'output' | 'temp',
});

const applyFilters = () => {
    router.get('/files', {
        search: search.value || undefined,
        type: type.value === 'all' ? undefined : type.value,
    }, {
        preserveState: true,
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

const deleteFile = (file: File) => {
    if (confirm('Are you sure you want to delete this file?')) {
        router.delete(`/files/${file.id}`);
    }
};

const getTypeColor = (type: string) => {
    const colors: Record<string, string> = {
        input: 'bg-blue-500',
        output: 'bg-green-500',
        temp: 'bg-gray-500',
    };
    return colors[type] || 'bg-gray-500';
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
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
</script>

<template>
    <AppLayout title="Files">
        <div class="space-y-6">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-3xl font-bold">Files</h1>
                    <p class="text-muted-foreground">Manage your uploaded files</p>
                </div>
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

            <Card>
                <CardHeader>
                    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <CardTitle>All Files</CardTitle>
                            <CardDescription>{{ files.total }} files total</CardDescription>
                        </div>
                        <div class="flex gap-2">
                            <div class="relative">
                                <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    v-model="search"
                                    placeholder="Search files..."
                                    class="pl-8 w-[200px]"
                                    @keyup.enter="applyFilters"
                                />
                            </div>
                            <Select v-model="type" @update:model-value="applyFilters">
                                <SelectTrigger class="w-[130px]">
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
                </CardHeader>
                <CardContent>
                    <Table>
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
                            <TableRow v-if="files.data.length === 0">
                                <TableCell colspan="6" class="text-center text-muted-foreground py-8">
                                    No files found. Upload your first file.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="file in files.data" :key="file.id">
                                <TableCell>
                                    <div class="flex items-center gap-2">
                                        <component :is="getFileIcon(file.mime_type)" class="h-4 w-4 text-muted-foreground" />
                                        <span class="font-medium truncate max-w-[200px]">
                                            {{ getFileName(file.path) }}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell>
                                    <Badge :class="getTypeColor(file.type)" variant="secondary">
                                        {{ file.type }}
                                    </Badge>
                                </TableCell>
                                <TableCell>{{ formatFileSize(file.size) }}</TableCell>
                                <TableCell class="text-sm text-muted-foreground">{{ file.mime_type }}</TableCell>
                                <TableCell>{{ formatDate(file.created_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <div class="flex justify-end gap-1">
                                        <Button variant="ghost" size="icon" as-child>
                                            <a :href="`/files/${file.id}`" download>
                                                <Download class="h-4 w-4" />
                                            </a>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            @click="deleteFile(file)"
                                            class="text-destructive hover:text-destructive"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </Button>
                                    </div>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>

                    <div v-if="files.last_page > 1" class="flex items-center justify-between mt-4">
                        <p class="text-sm text-muted-foreground">
                            Showing {{ files.from }} to {{ files.to }} of {{ files.total }} results
                        </p>
                        <div class="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="files.current_page === 1"
                                @click="router.get('/files', { page: files.current_page - 1 })"
                            >
                                Previous
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="files.current_page === files.last_page"
                                @click="router.get('/files', { page: files.current_page + 1 })"
                            >
                                Next
                            </Button>
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
