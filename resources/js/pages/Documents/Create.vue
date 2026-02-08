<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { AppLayout } from '@/layouts';
import { store } from '@/actions/App/Http/Controllers/Api/V1/DocumentController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Progress } from '@/components/ui/progress';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Upload, Link as LinkIcon, FileText, Loader2 } from 'lucide-vue-next';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    supportedTypes: string[];
    breadcrumbs: BreadcrumbItem[];
}>();

const activeTab = ref('upload');
const isSubmitting = ref(false);
const uploadProgress = ref(0);
const errors = ref<Record<string, string[]>>({});

// Upload form
const uploadFile = ref<File | null>(null);
const uploadTitle = ref('');

// URL form
const urlValue = ref('');
const urlTitle = ref('');

// Paste form
const pasteText = ref('');
const pasteTitle = ref('');

const handleFileSelect = (event: Event) => {
    const target = event.target as HTMLInputElement;
    if (target.files && target.files[0]) {
        uploadFile.value = target.files[0];
        if (!uploadTitle.value) {
            uploadTitle.value = target.files[0].name.replace(/\.[^/.]+$/, '');
        }
    }
};

const submitUpload = async () => {
    if (!uploadFile.value) return;

    isSubmitting.value = true;
    uploadProgress.value = 0;
    errors.value = {};

    const formData = new FormData();
    formData.append('source_type', 'upload');
    formData.append('file', uploadFile.value);
    if (uploadTitle.value) {
        formData.append('title', uploadTitle.value);
    }

    try {
        const xhr = new XMLHttpRequest();

        const uploadPromise = new Promise<{ ok: boolean; data: any }>((resolve, reject) => {
            xhr.upload.addEventListener('progress', (e) => {
                if (e.lengthComputable) {
                    uploadProgress.value = Math.round((e.loaded / e.total) * 100);
                }
            });

            xhr.addEventListener('load', () => {
                const data = JSON.parse(xhr.responseText);
                resolve({ ok: xhr.status >= 200 && xhr.status < 300, data });
            });

            xhr.addEventListener('error', () => reject(new Error('Upload failed')));
            xhr.addEventListener('abort', () => reject(new Error('Upload aborted')));

            xhr.open('POST', store.url());
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-XSRF-TOKEN', decodeURIComponent(
                document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
            ));
            xhr.send(formData);
        });

        const result = await uploadPromise;

        if (result.ok) {
            router.visit(`/documents/${result.data.data.id}`);
        } else if (result.data.errors) {
            errors.value = result.data.errors;
        }
    } catch {
        errors.value = { file: ['Upload failed. Please try again.'] };
    } finally {
        isSubmitting.value = false;
    }
};

const submitUrl = async () => {
    if (!urlValue.value) return;

    isSubmitting.value = true;
    errors.value = {};

    try {
        const response = await fetch(store.url(), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify({
                source_type: 'url',
                url: urlValue.value,
                title: urlTitle.value || undefined,
            }),
        });

        const data = await response.json();

        if (response.ok) {
            router.visit(`/documents/${data.data.id}`);
        } else if (data.errors) {
            errors.value = data.errors;
        }
    } catch {
        errors.value = { url: ['Failed to fetch URL. Please try again.'] };
    } finally {
        isSubmitting.value = false;
    }
};

const submitPaste = async () => {
    if (!pasteText.value) return;

    isSubmitting.value = true;
    errors.value = {};

    try {
        const response = await fetch(store.url(), {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify({
                source_type: 'paste',
                text: pasteText.value,
                title: pasteTitle.value || undefined,
            }),
        });

        const data = await response.json();

        if (response.ok) {
            router.visit(`/documents/${data.data.id}`);
        } else if (data.errors) {
            errors.value = data.errors;
        }
    } catch {
        errors.value = { text: ['Failed to create document. Please try again.'] };
    } finally {
        isSubmitting.value = false;
    }
};

const getError = (field: string) => {
    return errors.value[field]?.[0];
};
</script>

<template>
    <AppLayout title="Add Document">
        <div class="max-w-2xl mx-auto">
            <div class="mb-6">
                <h1 class="text-2xl font-semibold">Add Document</h1>
                <p class="text-sm text-muted-foreground mt-1">
                    Upload a file, fetch from URL, or paste text content.
                </p>
            </div>

            <Card>
                <Tabs v-model="activeTab" class="w-full">
                    <CardHeader class="pb-0">
                        <TabsList class="grid w-full grid-cols-3">
                            <TabsTrigger value="upload" class="gap-2">
                                <Upload class="h-4 w-4" />
                                Upload
                            </TabsTrigger>
                            <TabsTrigger value="url" class="gap-2">
                                <LinkIcon class="h-4 w-4" />
                                URL
                            </TabsTrigger>
                            <TabsTrigger value="paste" class="gap-2">
                                <FileText class="h-4 w-4" />
                                Paste
                            </TabsTrigger>
                        </TabsList>
                    </CardHeader>

                    <CardContent class="pt-6">
                        <!-- Upload Tab -->
                        <TabsContent value="upload" class="mt-0 space-y-4">
                            <div class="space-y-2">
                                <Label for="upload-file">File</Label>
                                <Input
                                    id="upload-file"
                                    type="file"
                                    :accept="supportedTypes.join(',')"
                                    @change="handleFileSelect"
                                    :disabled="isSubmitting"
                                />
                                <p v-if="getError('file')" class="text-sm text-destructive">
                                    {{ getError('file') }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Supported: {{ supportedTypes.slice(0, 4).join(', ') }}{{ supportedTypes.length > 4 ? '...' : '' }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="upload-title">Title (optional)</Label>
                                <Input
                                    id="upload-title"
                                    v-model="uploadTitle"
                                    placeholder="Document title"
                                    :disabled="isSubmitting"
                                />
                            </div>

                            <Progress v-if="isSubmitting && uploadProgress > 0" :model-value="uploadProgress" class="h-2" />

                            <div class="flex justify-end gap-2 pt-4">
                                <Button variant="outline" as-child>
                                    <a href="/documents">Cancel</a>
                                </Button>
                                <Button @click="submitUpload" :disabled="!uploadFile || isSubmitting">
                                    <Loader2 v-if="isSubmitting" class="h-4 w-4 mr-2 animate-spin" />
                                    {{ isSubmitting ? `Uploading... ${uploadProgress}%` : 'Upload' }}
                                </Button>
                            </div>
                        </TabsContent>

                        <!-- URL Tab -->
                        <TabsContent value="url" class="mt-0 space-y-4">
                            <div class="space-y-2">
                                <Label for="url-value">URL</Label>
                                <Input
                                    id="url-value"
                                    v-model="urlValue"
                                    type="url"
                                    placeholder="https://example.com/document.pdf"
                                    :disabled="isSubmitting"
                                />
                                <p v-if="getError('url')" class="text-sm text-destructive">
                                    {{ getError('url') }}
                                </p>
                            </div>

                            <div class="space-y-2">
                                <Label for="url-title">Title (optional)</Label>
                                <Input
                                    id="url-title"
                                    v-model="urlTitle"
                                    placeholder="Document title"
                                    :disabled="isSubmitting"
                                />
                            </div>

                            <div class="flex justify-end gap-2 pt-4">
                                <Button variant="outline" as-child>
                                    <a href="/documents">Cancel</a>
                                </Button>
                                <Button @click="submitUrl" :disabled="!urlValue || isSubmitting">
                                    <Loader2 v-if="isSubmitting" class="h-4 w-4 mr-2 animate-spin" />
                                    {{ isSubmitting ? 'Fetching...' : 'Fetch URL' }}
                                </Button>
                            </div>
                        </TabsContent>

                        <!-- Paste Tab -->
                        <TabsContent value="paste" class="mt-0 space-y-4">
                            <div class="space-y-2">
                                <Label for="paste-title">Title (optional)</Label>
                                <Input
                                    id="paste-title"
                                    v-model="pasteTitle"
                                    placeholder="Document title"
                                    :disabled="isSubmitting"
                                />
                            </div>

                            <div class="space-y-2">
                                <Label for="paste-text">Content</Label>
                                <Textarea
                                    id="paste-text"
                                    v-model="pasteText"
                                    placeholder="Paste your text content here..."
                                    class="min-h-48"
                                    :disabled="isSubmitting"
                                />
                                <p v-if="getError('text')" class="text-sm text-destructive">
                                    {{ getError('text') }}
                                </p>
                            </div>

                            <div class="flex justify-end gap-2 pt-4">
                                <Button variant="outline" as-child>
                                    <a href="/documents">Cancel</a>
                                </Button>
                                <Button @click="submitPaste" :disabled="!pasteText || isSubmitting">
                                    <Loader2 v-if="isSubmitting" class="h-4 w-4 mr-2 animate-spin" />
                                    {{ isSubmitting ? 'Creating...' : 'Create Document' }}
                                </Button>
                            </div>
                        </TabsContent>
                    </CardContent>
                </Tabs>
            </Card>
        </div>
    </AppLayout>
</template>
