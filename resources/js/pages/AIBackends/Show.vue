<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Cpu,
    CheckCircle2,
    XCircle,
    HelpCircle,
    Zap,
    MessageSquare,
    Eye,
    Star,
    RefreshCw,
    Download,
    MoreHorizontal,
    Trash2,
    Info,
    Loader2,
} from 'lucide-vue-next';
import { useModelPull } from '@/composables/useModelPull';
import type { AIBackend, AIModel } from '@/types';

interface ExtendedAIBackend extends AIBackend {
    supports_model_management: boolean;
}

const props = defineProps<{
    backend: ExtendedAIBackend;
}>();

const { pullState, progress, error: pullError, connect, reset } = useModelPull();

// State
const pullDialogOpen = ref(false);
const pullModelName = ref('');
const deleteDialogOpen = ref(false);
const modelToDelete = ref<AIModel | null>(null);
const deleting = ref(false);
const refreshing = ref(false);
const detailsDialogOpen = ref(false);
const selectedModel = ref<AIModel | null>(null);
const loadingDetails = ref(false);

// Computed
const pullProgress = computed(() => {
    if (!progress.value?.total || !progress.value?.completed) return 0;
    return Math.round((progress.value.completed / progress.value.total) * 100);
});

const isPulling = computed(() => pullState.value === 'pulling' || pullState.value === 'connecting');

// Methods
const getStatusIcon = (status: AIBackend['status']) => {
    const icons = {
        connected: CheckCircle2,
        error: XCircle,
        unknown: HelpCircle,
    };
    return icons[status] || HelpCircle;
};

const getStatusClass = (status: AIBackend['status']) => {
    const classes = {
        connected: 'text-green-500',
        error: 'text-red-500',
        unknown: 'text-muted-foreground',
    };
    return classes[status] || 'text-muted-foreground';
};

const formatBytes = (bytes: number | undefined) => {
    if (!bytes) return '-';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let unitIndex = 0;
    let size = bytes;
    while (size >= 1024 && unitIndex < units.length - 1) {
        size /= 1024;
        unitIndex++;
    }
    return `${size.toFixed(1)} ${units[unitIndex]}`;
};

const formatDate = (date: string | undefined) => {
    if (!date) return '-';
    return new Date(date).toLocaleDateString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
    });
};

const refreshModels = () => {
    refreshing.value = true;
    router.reload({
        only: ['backend'],
        onFinish: () => {
            refreshing.value = false;
            toast.success('Models refreshed');
        },
    });
};

const openPullDialog = () => {
    reset();
    pullModelName.value = '';
    pullDialogOpen.value = true;
};

const startPull = async () => {
    if (!pullModelName.value.trim()) return;

    try {
        const response = await fetch(`/api/v1/ai-backends/${props.backend.name}/models/pull`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                ),
            },
            body: JSON.stringify({ model: pullModelName.value.trim() }),
        });

        if (!response.ok) {
            const data = await response.json();
            toast.error(data.error || 'Failed to start model pull');
            return;
        }

        const data = await response.json();

        connect(data.stream_url, {
            onCompleted: () => {
                toast.success(`Model "${pullModelName.value}" pulled successfully`);
                router.reload({ only: ['backend'] });
            },
            onFailed: (error) => {
                toast.error(`Failed to pull model: ${error}`);
            },
        });
    } catch (e) {
        toast.error('Failed to start model pull');
    }
};

const closePullDialog = () => {
    if (!isPulling.value) {
        pullDialogOpen.value = false;
        reset();
    }
};

const confirmDelete = (model: AIModel) => {
    modelToDelete.value = model;
    deleteDialogOpen.value = true;
};

const deleteModel = async () => {
    if (!modelToDelete.value) return;

    deleting.value = true;
    try {
        const response = await fetch(
            `/api/v1/ai-backends/${props.backend.name}/models/${encodeURIComponent(modelToDelete.value.name)}`,
            {
                method: 'DELETE',
                headers: {
                    'Accept': 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] || ''
                    ),
                },
            }
        );

        if (response.ok) {
            toast.success(`Model "${modelToDelete.value.name}" deleted`);
            router.reload({ only: ['backend'] });
            deleteDialogOpen.value = false;
        } else {
            const data = await response.json();
            toast.error(data.error || 'Failed to delete model');
        }
    } catch (e) {
        toast.error('Failed to delete model');
    } finally {
        deleting.value = false;
        modelToDelete.value = null;
    }
};

const showModelDetails = async (model: AIModel) => {
    selectedModel.value = model;
    detailsDialogOpen.value = true;
    loadingDetails.value = true;

    try {
        const response = await fetch(
            `/api/v1/ai-backends/${props.backend.name}/models/${encodeURIComponent(model.name)}`,
            {
                headers: {
                    'Accept': 'application/json',
                },
            }
        );

        if (response.ok) {
            const data = await response.json();
            selectedModel.value = data;
        }
    } catch (e) {
        console.error('Failed to fetch model details:', e);
    } finally {
        loadingDetails.value = false;
    }
};
</script>

<template>
    <AppLayout :title="`AI Backend: ${backend.name}`">
        <div class="max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-center justify-between mb-6">
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-semibold">{{ backend.name }}</h1>
                        <Badge v-if="backend.is_default" variant="secondary" class="gap-1">
                            <Star class="h-3 w-3 fill-current" />
                            Default
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground mt-1">
                        {{ backend.driver }} backend
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <Button
                        v-if="backend.supports_model_management"
                        variant="outline"
                        :disabled="refreshing"
                        @click="refreshModels"
                    >
                        <RefreshCw :class="['h-4 w-4 mr-2', { 'animate-spin': refreshing }]" />
                        Refresh
                    </Button>
                    <Button
                        v-if="backend.supports_model_management"
                        @click="openPullDialog"
                    >
                        <Download class="h-4 w-4 mr-2" />
                        Pull Model
                    </Button>
                </div>
            </div>

            <!-- Status Card -->
            <Card class="mb-6">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2">
                        <Cpu class="h-5 w-5" />
                        Status
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <!-- Connection Status -->
                        <div>
                            <p class="text-sm text-muted-foreground mb-1">Connection</p>
                            <div class="flex items-center gap-2">
                                <component
                                    :is="getStatusIcon(backend.status)"
                                    :class="['h-4 w-4', getStatusClass(backend.status)]"
                                />
                                <span class="font-medium capitalize">{{ backend.status }}</span>
                            </div>
                            <p v-if="backend.error" class="text-xs text-red-500 mt-1">
                                {{ backend.error }}
                            </p>
                        </div>

                        <!-- Default Model -->
                        <div v-if="backend.model">
                            <p class="text-sm text-muted-foreground mb-1">Default Model</p>
                            <p class="font-medium">{{ backend.model }}</p>
                        </div>

                        <!-- Capabilities -->
                        <div class="sm:col-span-2">
                            <p class="text-sm text-muted-foreground mb-2">Capabilities</p>
                            <div class="flex flex-wrap gap-2">
                                <Badge
                                    v-if="backend.capabilities.streaming"
                                    variant="secondary"
                                    class="gap-1"
                                >
                                    <Zap class="h-3 w-3" />
                                    Streaming
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.function_calling"
                                    variant="secondary"
                                    class="gap-1"
                                >
                                    <MessageSquare class="h-3 w-3" />
                                    Function Calling
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.vision"
                                    variant="secondary"
                                    class="gap-1"
                                >
                                    <Eye class="h-3 w-3" />
                                    Vision
                                </Badge>
                                <Badge
                                    v-if="backend.capabilities.max_context"
                                    variant="outline"
                                    class="gap-1"
                                >
                                    {{ backend.capabilities.max_context.toLocaleString() }} ctx
                                </Badge>
                            </div>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <!-- Models Table -->
            <Card v-if="backend.supports_model_management">
                <CardHeader>
                    <CardTitle>Models</CardTitle>
                    <CardDescription>
                        {{ backend.models.length }} model{{ backend.models.length !== 1 ? 's' : '' }} available
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <div v-if="backend.models.length === 0" class="text-center py-8">
                        <p class="text-muted-foreground mb-4">No models installed yet.</p>
                        <Button @click="openPullDialog">
                            <Download class="h-4 w-4 mr-2" />
                            Pull Your First Model
                        </Button>
                    </div>

                    <Table v-else>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Name</TableHead>
                                <TableHead>Size</TableHead>
                                <TableHead>Parameters</TableHead>
                                <TableHead>Modified</TableHead>
                                <TableHead class="w-12"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="model in backend.models"
                                :key="model.name"
                                class="cursor-pointer"
                                @click="showModelDetails(model)"
                            >
                                <TableCell class="font-medium">
                                    {{ model.name }}
                                </TableCell>
                                <TableCell>
                                    {{ model.size_human || formatBytes(model.size) }}
                                </TableCell>
                                <TableCell>
                                    {{ model.parameter_size || '-' }}
                                </TableCell>
                                <TableCell>
                                    {{ formatDate(model.modified_at) }}
                                </TableCell>
                                <TableCell>
                                    <DropdownMenu>
                                        <DropdownMenuTrigger as-child>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8"
                                                @click.stop
                                            >
                                                <MoreHorizontal class="h-4 w-4" />
                                            </Button>
                                        </DropdownMenuTrigger>
                                        <DropdownMenuContent align="end">
                                            <DropdownMenuItem @click.stop="showModelDetails(model)">
                                                <Info class="mr-2 h-4 w-4" />
                                                Details
                                            </DropdownMenuItem>
                                            <DropdownMenuItem
                                                class="text-destructive"
                                                @click.stop="confirmDelete(model)"
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
                </CardContent>
            </Card>

            <!-- Pull Model Dialog -->
            <Dialog :open="pullDialogOpen" @update:open="closePullDialog">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Pull Model</DialogTitle>
                        <DialogDescription>
                            Enter the name of the model to download (e.g., llama3.2, mistral:7b)
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-4 py-4">
                        <div class="space-y-2">
                            <Label for="modelName">Model Name</Label>
                            <Input
                                id="modelName"
                                v-model="pullModelName"
                                placeholder="llama3.2:latest"
                                :disabled="isPulling"
                                @keyup.enter="startPull"
                            />
                        </div>

                        <!-- Progress -->
                        <div v-if="isPulling || pullState === 'completed' || pullState === 'failed'" class="space-y-2">
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-muted-foreground">
                                    {{ progress?.status || 'Connecting...' }}
                                </span>
                                <span v-if="pullProgress > 0">{{ pullProgress }}%</span>
                            </div>
                            <Progress :model-value="pullProgress" />
                            <p v-if="pullState === 'completed'" class="text-sm text-green-500">
                                Download completed!
                            </p>
                            <p v-if="pullState === 'failed'" class="text-sm text-red-500">
                                {{ pullError }}
                            </p>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="isPulling"
                            @click="closePullDialog"
                        >
                            {{ pullState === 'completed' ? 'Close' : 'Cancel' }}
                        </Button>
                        <Button
                            v-if="pullState !== 'completed'"
                            :disabled="!pullModelName.trim() || isPulling"
                            @click="startPull"
                        >
                            <Loader2 v-if="isPulling" class="h-4 w-4 mr-2 animate-spin" />
                            {{ isPulling ? 'Pulling...' : 'Pull' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Delete Confirmation Dialog -->
            <Dialog v-model:open="deleteDialogOpen">
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Model</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete "{{ modelToDelete?.name }}"?
                            This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            :disabled="deleting"
                            @click="deleteDialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            :disabled="deleting"
                            @click="deleteModel"
                        >
                            <Loader2 v-if="deleting" class="h-4 w-4 mr-2 animate-spin" />
                            {{ deleting ? 'Deleting...' : 'Delete' }}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <!-- Model Details Dialog -->
            <Dialog v-model:open="detailsDialogOpen">
                <DialogContent class="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{{ selectedModel?.name }}</DialogTitle>
                        <DialogDescription>Model details and information</DialogDescription>
                    </DialogHeader>

                    <div v-if="loadingDetails" class="flex justify-center py-8">
                        <Loader2 class="h-6 w-6 animate-spin text-muted-foreground" />
                    </div>

                    <div v-else-if="selectedModel" class="space-y-4">
                        <div class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <p class="text-muted-foreground">Family</p>
                                <p class="font-medium">{{ selectedModel.family || '-' }}</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Parameters</p>
                                <p class="font-medium">{{ selectedModel.parameter_size || '-' }}</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Quantization</p>
                                <p class="font-medium">{{ selectedModel.quantization_level || '-' }}</p>
                            </div>
                            <div>
                                <p class="text-muted-foreground">Size</p>
                                <p class="font-medium">{{ selectedModel.size_human || formatBytes(selectedModel.size) }}</p>
                            </div>
                        </div>

                        <div v-if="selectedModel.digest">
                            <p class="text-sm text-muted-foreground mb-1">Digest</p>
                            <code class="text-xs bg-muted px-2 py-1 rounded break-all">
                                {{ selectedModel.digest }}
                            </code>
                        </div>
                    </div>

                    <DialogFooter>
                        <Button variant="outline" @click="detailsDialogOpen = false">
                            Close
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    </AppLayout>
</template>
