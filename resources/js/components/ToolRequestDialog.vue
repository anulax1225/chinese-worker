<script setup lang="ts">
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Badge } from '@/components/ui/badge';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { AlertTriangle, Terminal, FileText, Wrench } from 'lucide-vue-next';
import type { ToolRequest } from '@/types';

const props = defineProps<{
    toolRequest: ToolRequest | null;
    isOpen: boolean;
    isSubmitting?: boolean;
}>();

const emit = defineEmits<{
    approve: [callId: string];
    reject: [callId: string, reason: string];
    close: [];
}>();

const rejectReason = ref('');

const toolIcon = computed(() => {
    if (!props.toolRequest) return Wrench;
    const name = props.toolRequest.name.toLowerCase();
    if (name.includes('bash') || name.includes('command') || name.includes('shell')) {
        return Terminal;
    }
    if (name.includes('file') || name.includes('read') || name.includes('write')) {
        return FileText;
    }
    return Wrench;
});

const isDangerous = computed(() => {
    if (!props.toolRequest) return false;
    const name = props.toolRequest.name.toLowerCase();
    const args = JSON.stringify(props.toolRequest.arguments).toLowerCase();

    // Check for potentially dangerous operations
    const dangerousPatterns = [
        'rm ', 'rm -', 'delete', 'remove', 'drop', 'truncate',
        'sudo', 'chmod', 'chown', '/etc/', '/root/',
        'password', 'secret', 'token', 'key'
    ];

    return dangerousPatterns.some(pattern =>
        name.includes(pattern) || args.includes(pattern)
    );
});

const formattedArguments = computed(() => {
    if (!props.toolRequest?.arguments) return '';

    try {
        // Special formatting for common argument types
        const args = props.toolRequest.arguments;

        // If it's a bash/command tool, show the command prominently
        if ('command' in args && typeof args.command === 'string') {
            return args.command;
        }

        // If it's a file operation, show the path
        if ('path' in args && typeof args.path === 'string') {
            return `Path: ${args.path}\n${JSON.stringify(args, null, 2)}`;
        }

        return JSON.stringify(args, null, 2);
    } catch {
        return String(props.toolRequest?.arguments || '');
    }
});

const handleApprove = () => {
    if (props.toolRequest) {
        emit('approve', props.toolRequest.call_id);
    }
};

const handleReject = () => {
    if (props.toolRequest) {
        emit('reject', props.toolRequest.call_id, rejectReason.value || 'User rejected');
        rejectReason.value = '';
    }
};

const handleClose = () => {
    emit('close');
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="handleClose">
        <DialogContent class="max-w-2xl">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <component :is="toolIcon" class="h-5 w-5" />
                    Tool Request: {{ toolRequest?.name }}
                    <Badge v-if="isDangerous" variant="destructive" class="ml-2">
                        <AlertTriangle class="h-3 w-3 mr-1" />
                        Review Carefully
                    </Badge>
                </DialogTitle>
                <DialogDescription>
                    The AI wants to use this tool. Review the arguments and approve or reject.
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-4 py-4">
                <div class="space-y-2">
                    <Label>Arguments</Label>
                    <pre
                        :class="[
                            'p-4 rounded-lg text-sm font-mono whitespace-pre-wrap overflow-auto max-h-[300px]',
                            isDangerous ? 'bg-destructive/10 border border-destructive/20' : 'bg-muted'
                        ]"
                    >{{ formattedArguments }}</pre>
                </div>

                <div v-if="isDangerous" class="flex items-start gap-2 p-3 rounded-lg bg-yellow-500/10 border border-yellow-500/20">
                    <AlertTriangle class="h-5 w-5 text-yellow-600 flex-shrink-0 mt-0.5" />
                    <p class="text-sm text-yellow-800 dark:text-yellow-200">
                        This operation may modify or delete files, or access sensitive data.
                        Please review carefully before approving.
                    </p>
                </div>

                <div class="space-y-2">
                    <Label for="reject-reason">Rejection reason (optional)</Label>
                    <Textarea
                        id="reject-reason"
                        v-model="rejectReason"
                        placeholder="Explain why you're rejecting this tool request..."
                        rows="2"
                    />
                </div>
            </div>

            <DialogFooter class="flex-col sm:flex-row gap-2">
                <Button
                    variant="destructive"
                    @click="handleReject"
                    :disabled="isSubmitting"
                >
                    Reject
                </Button>
                <Button
                    variant="default"
                    @click="handleApprove"
                    :disabled="isSubmitting"
                >
                    {{ isSubmitting ? 'Submitting...' : 'Approve' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
