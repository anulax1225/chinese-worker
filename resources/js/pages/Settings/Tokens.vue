<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Plus, Copy, Trash2, Key, Check } from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import type { PersonalAccessToken, AppPageProps } from '@/types';

const props = defineProps<{
    tokens: PersonalAccessToken[];
}>();

const page = usePage<AppPageProps>();
const createDialogOpen = ref(false);
const newToken = ref<string | null>(null);
const copied = ref(false);

const form = useForm({
    name: '',
});

// Watch for flash token (newly created)
watch(
    () => page.props.flash?.token,
    (token) => {
        if (token) {
            newToken.value = token;
        }
    },
    { immediate: true }
);

const createToken = () => {
    form.post('/settings/tokens', {
        onSuccess: () => {
            createDialogOpen.value = false;
            form.reset();
        },
    });
};

const deleteToken = (token: PersonalAccessToken) => {
    if (confirm(`Are you sure you want to delete the token "${token.name}"?`)) {
        form.delete(`/settings/tokens/${token.id}`);
    }
};

const copyToken = async () => {
    if (!newToken.value) return;
    await navigator.clipboard.writeText(newToken.value);
    copied.value = true;
    toast.success('Token copied to clipboard');
    setTimeout(() => {
        copied.value = false;
    }, 2000);
};

const dismissToken = () => {
    newToken.value = null;
};

const formatDate = (date: string | null) => {
    if (!date) return 'Never';
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};
</script>

<template>
    <AppLayout title="API Tokens">
        <div class="space-y-4 max-w-4xl">
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-xl font-semibold">API Tokens</h1>
                    <p class="text-sm text-muted-foreground">Manage your API access tokens</p>
                </div>
                <Dialog v-model:open="createDialogOpen">
                    <DialogTrigger as-child>
                        <Button>
                            <Plus class="h-4 w-4 mr-2" />
                            Create Token
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Create API Token</DialogTitle>
                            <DialogDescription>Create a new API token for external access</DialogDescription>
                        </DialogHeader>
                        <form @submit.prevent="createToken" class="space-y-4 py-4">
                            <div class="space-y-2">
                                <Label for="name">Token Name</Label>
                                <Input
                                    id="name"
                                    v-model="form.name"
                                    placeholder="My API Token"
                                    required
                                />
                                <p v-if="form.errors.name" class="text-sm text-destructive">
                                    {{ form.errors.name }}
                                </p>
                            </div>
                        </form>
                        <DialogFooter>
                            <Button variant="outline" @click="createDialogOpen = false">
                                Cancel
                            </Button>
                            <Button @click="createToken" :disabled="form.processing || !form.name">
                                {{ form.processing ? 'Creating...' : 'Create Token' }}
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>

            <!-- New Token Alert -->
            <Alert v-if="newToken" class="border-green-500 bg-green-50 dark:bg-green-950">
                <Key class="h-4 w-4 text-green-600" />
                <AlertTitle class="text-green-800 dark:text-green-200">Token Created Successfully</AlertTitle>
                <AlertDescription class="mt-2">
                    <p class="text-green-700 dark:text-green-300 mb-2">
                        Make sure to copy your token now. You won't be able to see it again!
                    </p>
                    <div class="flex items-center gap-2">
                        <code class="flex-1 p-2 bg-white dark:bg-gray-900 rounded border font-mono text-sm break-all">
                            {{ newToken }}
                        </code>
                        <Button variant="outline" size="icon" @click="copyToken">
                            <Check v-if="copied" class="h-4 w-4 text-green-600" />
                            <Copy v-else class="h-4 w-4" />
                        </Button>
                    </div>
                    <Button variant="ghost" size="sm" class="mt-2" @click="dismissToken">
                        Dismiss
                    </Button>
                </AlertDescription>
            </Alert>

            <Card>
                <CardHeader>
                    <CardTitle>Your Tokens</CardTitle>
                    <CardDescription>
                        API tokens allow external applications to authenticate with the API on your behalf.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead class="text-xs uppercase tracking-wide">Name</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Last Used</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide">Created</TableHead>
                                <TableHead class="text-xs uppercase tracking-wide text-right">Actions</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-if="tokens.length === 0">
                                <TableCell colspan="4" class="text-center text-muted-foreground py-8">
                                    No API tokens yet. Create your first token.
                                </TableCell>
                            </TableRow>
                            <TableRow v-for="token in tokens" :key="token.id">
                                <TableCell class="font-medium">{{ token.name }}</TableCell>
                                <TableCell>{{ formatDate(token.last_used_at) }}</TableCell>
                                <TableCell>{{ formatDate(token.created_at) }}</TableCell>
                                <TableCell class="text-right">
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        @click="deleteToken(token)"
                                        class="text-destructive hover:text-destructive"
                                    >
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>
