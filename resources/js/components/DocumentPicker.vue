<script setup lang="ts">
import { ref, computed } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Check, FileText, Search } from 'lucide-vue-next';
import type { Document } from '@/types';

const props = defineProps<{
    documents: Document[];
    selectedIds: number[];
    isOpen: boolean;
}>();

const emit = defineEmits<{
    'update:isOpen': [value: boolean];
    select: [id: number];
    deselect: [id: number];
}>();

const search = ref('');

const filteredDocuments = computed(() => {
    if (!search.value) return props.documents;
    const term = search.value.toLowerCase();
    return props.documents.filter(d =>
        d.title?.toLowerCase().includes(term)
    );
});

const isSelected = (id: number) => props.selectedIds.includes(id);

const toggleSelect = (doc: Document) => {
    if (isSelected(doc.id)) {
        emit('deselect', doc.id);
    } else {
        emit('select', doc.id);
    }
};

const formatFileSize = (bytes: number) => {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="emit('update:isOpen', $event)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Attach Documents</DialogTitle>
            </DialogHeader>

            <div class="space-y-4">
                <!-- Search input -->
                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
                    <Input
                        v-model="search"
                        placeholder="Search documents..."
                        class="pl-8"
                    />
                </div>

                <!-- Document list -->
                <div class="max-h-64 overflow-y-auto space-y-1">
                    <template v-if="filteredDocuments.length > 0">
                        <button
                            v-for="doc in filteredDocuments"
                            :key="doc.id"
                            type="button"
                            class="w-full flex items-center gap-3 p-2 rounded-lg hover:bg-muted transition-colors text-left"
                            @click="toggleSelect(doc)"
                        >
                            <!-- Checkbox indicator -->
                            <div
                                :class="[
                                    'w-5 h-5 rounded border flex items-center justify-center shrink-0 transition-colors',
                                    isSelected(doc.id)
                                        ? 'bg-primary border-primary text-primary-foreground'
                                        : 'border-muted-foreground/30'
                                ]"
                            >
                                <Check v-if="isSelected(doc.id)" class="w-3 h-3" />
                            </div>

                            <!-- Document icon -->
                            <div class="flex items-center justify-center w-8 h-8 rounded bg-muted shrink-0">
                                <FileText class="h-4 w-4 text-muted-foreground" />
                            </div>

                            <!-- Document info -->
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium truncate">
                                    {{ doc.title || 'Untitled' }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatFileSize(doc.file_size) }}
                                </p>
                            </div>

                            <!-- Status badge -->
                            <Badge
                                variant="outline"
                                class="text-xs bg-green-500/10 text-green-600 border-green-500/20 shrink-0"
                            >
                                ready
                            </Badge>
                        </button>
                    </template>

                    <div v-else class="text-center py-8 text-muted-foreground text-sm">
                        <FileText class="w-8 h-8 mx-auto mb-2 opacity-50" />
                        <p v-if="documents.length === 0">No documents available</p>
                        <p v-else>No documents match your search</p>
                    </div>
                </div>

                <!-- Selection count -->
                <div v-if="selectedIds.length > 0" class="text-xs text-muted-foreground text-center">
                    {{ selectedIds.length }} document{{ selectedIds.length !== 1 ? 's' : '' }} selected
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
