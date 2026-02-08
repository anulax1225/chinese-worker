<script setup lang="ts">
import { useForm, router, usePage } from '@inertiajs/vue3';
import { ref, watch, computed } from 'vue';
import DOMPurify from 'dompurify';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
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
import {
    User,
    Lock,
    Terminal,
    ShieldCheck,
    Shield,
    ShieldOff,
    Plus,
    Copy,
    Trash2,
    Key,
    Check,
} from 'lucide-vue-next';
import { toast } from 'vue-sonner';
import type { User as UserType, PersonalAccessToken, AppPageProps } from '@/types';

const props = defineProps<{
    user: UserType;
    tokens: PersonalAccessToken[];
    twoFactorEnabled: boolean;
    twoFactorConfirmed: boolean;
}>();

const page = usePage<AppPageProps>();

// Determine initial tab from URL hash
const getInitialTab = () => {
    const hash = window.location.hash.slice(1);
    if (['profile', 'password', 'tokens', 'two-factor'].includes(hash)) {
        return hash;
    }
    return 'profile';
};

const activeTab = ref(getInitialTab());

// Update URL hash when tab changes
watch(activeTab, (newTab) => {
    window.history.replaceState(null, '', `#${newTab}`);
});

// Profile form
const profileForm = useForm({
    name: props.user.name,
    email: props.user.email,
});

const submitProfile = () => {
    profileForm.put('/settings/profile');
};

// Password form
const passwordForm = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const submitPassword = () => {
    passwordForm.put('/settings/password', {
        onSuccess: () => passwordForm.reset(),
    });
};

// Tokens
const createDialogOpen = ref(false);
const newToken = ref<string | null>(null);
const copied = ref(false);

const tokenForm = useForm({
    name: '',
});

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
    tokenForm.post('/settings/tokens', {
        onSuccess: () => {
            createDialogOpen.value = false;
            tokenForm.reset();
        },
    });
};

const deleteToken = (token: PersonalAccessToken) => {
    if (confirm(`Are you sure you want to delete the token "${token.name}"?`)) {
        tokenForm.delete(`/settings/tokens/${token.id}`);
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

// Two-factor
const enabling = ref(false);
const confirming = ref(false);
const qrCode = ref<string | null>(null);
const setupKey = ref<string | null>(null);
const recoveryCodes = ref<string[] | null>(null);

// Sanitize QR code SVG to prevent XSS attacks
const sanitizedQrCode = computed(() => {
    if (!qrCode.value) return null;
    return DOMPurify.sanitize(qrCode.value, {
        USE_PROFILES: { svg: true, svgFilters: true },
        ADD_TAGS: ['svg', 'path', 'rect', 'g'],
        ADD_ATTR: ['viewBox', 'd', 'fill', 'xmlns'],
    });
});

const confirmForm = useForm({
    code: '',
});

const enableTwoFactor = async () => {
    enabling.value = true;

    try {
        await router.post('/user/two-factor-authentication', {}, {
            preserveScroll: true,
            onSuccess: async () => {
                const qrResponse = await fetch('/user/two-factor-qr-code');
                const qrData = await qrResponse.json();
                qrCode.value = qrData.svg;

                const keyResponse = await fetch('/user/two-factor-secret-key');
                const keyData = await keyResponse.json();
                setupKey.value = keyData.secretKey;

                confirming.value = true;
            },
        });
    } finally {
        enabling.value = false;
    }
};

const confirmTwoFactor = () => {
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        preserveScroll: true,
        onSuccess: async () => {
            confirming.value = false;
            qrCode.value = null;
            setupKey.value = null;

            const response = await fetch('/user/two-factor-recovery-codes');
            const codes = await response.json();
            recoveryCodes.value = codes;
        },
        onError: () => {
            confirmForm.reset();
        },
    });
};

const disableTwoFactor = () => {
    if (!confirm('Are you sure you want to disable two-factor authentication?')) return;

    router.delete('/user/two-factor-authentication', {
        preserveScroll: true,
    });
};

const regenerateRecoveryCodes = async () => {
    await router.post('/user/two-factor-recovery-codes', {}, {
        preserveScroll: true,
        onSuccess: async () => {
            const response = await fetch('/user/two-factor-recovery-codes');
            const codes = await response.json();
            recoveryCodes.value = codes;
        },
    });
};

const dismissRecoveryCodes = () => {
    recoveryCodes.value = null;
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
    <AppLayout title="Settings">
        <div class="space-y-4 mx-auto max-w-4xl">
            <div>
                <h1 class="font-semibold text-xl">Settings</h1>
                <p class="text-muted-foreground text-sm">Manage your account settings and preferences</p>
            </div>

            <Tabs v-model="activeTab" class="w-full">
                <TabsList class="grid grid-cols-4 w-full">
                    <TabsTrigger value="profile" class="flex items-center gap-2">
                        <User class="w-4 h-4" />
                        <span class="hidden sm:inline">Profile</span>
                    </TabsTrigger>
                    <TabsTrigger value="password" class="flex items-center gap-2">
                        <Lock class="w-4 h-4" />
                        <span class="hidden sm:inline">Password</span>
                    </TabsTrigger>
                    <TabsTrigger value="tokens" class="flex items-center gap-2">
                        <Terminal class="w-4 h-4" />
                        <span class="hidden sm:inline">API Tokens</span>
                    </TabsTrigger>
                    <TabsTrigger value="two-factor" class="flex items-center gap-2">
                        <ShieldCheck class="w-4 h-4" />
                        <span class="hidden sm:inline">2FA</span>
                    </TabsTrigger>
                </TabsList>

                <!-- Profile Tab -->
                <TabsContent value="profile" class="mt-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Profile Information</CardTitle>
                            <CardDescription>Update your account's profile information and email address.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form @submit.prevent="submitProfile" class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="name">Name</Label>
                                    <Input
                                        id="name"
                                        v-model="profileForm.name"
                                        type="text"
                                        required
                                        autocomplete="name"
                                    />
                                    <p v-if="profileForm.errors.name" class="text-destructive text-sm">
                                        {{ profileForm.errors.name }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="email">Email</Label>
                                    <Input
                                        id="email"
                                        v-model="profileForm.email"
                                        type="email"
                                        required
                                        autocomplete="email"
                                    />
                                    <p v-if="profileForm.errors.email" class="text-destructive text-sm">
                                        {{ profileForm.errors.email }}
                                    </p>
                                </div>

                                <div class="flex justify-end">
                                    <Button type="submit" :disabled="profileForm.processing">
                                        {{ profileForm.processing ? 'Saving...' : 'Save Changes' }}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Password Tab -->
                <TabsContent value="password" class="mt-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Update Password</CardTitle>
                            <CardDescription>Ensure your account is using a long, random password to stay secure.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form @submit.prevent="submitPassword" class="space-y-4">
                                <div class="space-y-2">
                                    <Label for="current_password">Current Password</Label>
                                    <Input
                                        id="current_password"
                                        v-model="passwordForm.current_password"
                                        type="password"
                                        required
                                        autocomplete="current-password"
                                    />
                                    <p v-if="passwordForm.errors.current_password" class="text-destructive text-sm">
                                        {{ passwordForm.errors.current_password }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="password">New Password</Label>
                                    <Input
                                        id="password"
                                        v-model="passwordForm.password"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                    />
                                    <p v-if="passwordForm.errors.password" class="text-destructive text-sm">
                                        {{ passwordForm.errors.password }}
                                    </p>
                                </div>

                                <div class="space-y-2">
                                    <Label for="password_confirmation">Confirm Password</Label>
                                    <Input
                                        id="password_confirmation"
                                        v-model="passwordForm.password_confirmation"
                                        type="password"
                                        required
                                        autocomplete="new-password"
                                    />
                                </div>

                                <div class="flex justify-end">
                                    <Button type="submit" :disabled="passwordForm.processing">
                                        {{ passwordForm.processing ? 'Updating...' : 'Update Password' }}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- API Tokens Tab -->
                <TabsContent value="tokens" class="space-y-4 mt-4">
                    <!-- New Token Alert -->
                    <Alert v-if="newToken" class="bg-green-50 dark:bg-green-950 border-green-500">
                        <Key class="w-4 h-4 text-green-600" />
                        <AlertTitle class="text-green-800 dark:text-green-200">Token Created Successfully</AlertTitle>
                        <AlertDescription class="mt-2">
                            <p class="mb-2 text-green-700 dark:text-green-300">
                                Make sure to copy your token now. You won't be able to see it again!
                            </p>
                            <div class="flex items-center gap-2">
                                <code class="flex-1 bg-white dark:bg-gray-900 p-2 border rounded font-mono text-sm break-all">
                                    {{ newToken }}
                                </code>
                                <Button variant="outline" size="icon" @click="copyToken">
                                    <Check v-if="copied" class="w-4 h-4 text-green-600" />
                                    <Copy v-else class="w-4 h-4" />
                                </Button>
                            </div>
                            <Button variant="ghost" size="sm" class="mt-2" @click="dismissToken">
                                Dismiss
                            </Button>
                        </AlertDescription>
                    </Alert>

                    <Card>
                        <CardHeader>
                            <div class="flex justify-between items-center">
                                <div>
                                    <CardTitle>API Tokens</CardTitle>
                                    <CardDescription>
                                        API tokens allow external applications to authenticate with the API on your behalf.
                                    </CardDescription>
                                </div>
                                <Dialog v-model:open="createDialogOpen">
                                    <DialogTrigger as-child>
                                        <Button>
                                            <Plus class="mr-2 w-4 h-4" />
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
                                                <Label for="token_name">Token Name</Label>
                                                <Input
                                                    id="token_name"
                                                    v-model="tokenForm.name"
                                                    placeholder="My API Token"
                                                    required
                                                />
                                                <p v-if="tokenForm.errors.name" class="text-destructive text-sm">
                                                    {{ tokenForm.errors.name }}
                                                </p>
                                            </div>
                                        </form>
                                        <DialogFooter>
                                            <Button variant="outline" @click="createDialogOpen = false">
                                                Cancel
                                            </Button>
                                            <Button @click="createToken" :disabled="tokenForm.processing || !tokenForm.name">
                                                {{ tokenForm.processing ? 'Creating...' : 'Create Token' }}
                                            </Button>
                                        </DialogFooter>
                                    </DialogContent>
                                </Dialog>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead class="text-xs uppercase tracking-wide">Name</TableHead>
                                        <TableHead class="text-xs uppercase tracking-wide">Last Used</TableHead>
                                        <TableHead class="text-xs uppercase tracking-wide">Created</TableHead>
                                        <TableHead class="text-xs text-right uppercase tracking-wide">Actions</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    <TableRow v-if="tokens.length === 0">
                                        <TableCell colspan="4" class="py-8 text-muted-foreground text-center">
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
                                                <Trash2 class="w-4 h-4" />
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </TabsContent>

                <!-- Two-Factor Tab -->
                <TabsContent value="two-factor" class="space-y-4 mt-4">
                    <!-- Recovery Codes Alert -->
                    <Alert v-if="recoveryCodes" class="bg-yellow-50 dark:bg-yellow-950 border-yellow-500">
                        <Key class="w-4 h-4 text-yellow-600" />
                        <AlertTitle class="text-yellow-800 dark:text-yellow-200">Recovery Codes</AlertTitle>
                        <AlertDescription class="mt-2">
                            <p class="mb-4 text-yellow-700 dark:text-yellow-300">
                                Store these recovery codes in a secure location. They can be used to recover
                                access to your account if you lose your authenticator device.
                            </p>
                            <div class="gap-2 grid grid-cols-2 mb-4">
                                <code
                                    v-for="code in recoveryCodes"
                                    :key="code"
                                    class="bg-white dark:bg-gray-900 p-2 border rounded font-mono text-sm text-center"
                                >
                                    {{ code }}
                                </code>
                            </div>
                            <Button variant="ghost" size="sm" @click="dismissRecoveryCodes">
                                I've saved these codes
                            </Button>
                        </AlertDescription>
                    </Alert>

                    <Card>
                        <CardHeader>
                            <div class="flex items-center gap-3">
                                <div :class="[
                                    'p-2 rounded-lg',
                                    twoFactorEnabled && twoFactorConfirmed ? 'bg-green-100 dark:bg-green-900' : 'bg-muted'
                                ]">
                                    <ShieldCheck
                                        v-if="twoFactorEnabled && twoFactorConfirmed"
                                        class="w-6 h-6 text-green-600"
                                    />
                                    <Shield v-else class="w-6 h-6 text-muted-foreground" />
                                </div>
                                <div>
                                    <CardTitle>
                                        {{ twoFactorEnabled && twoFactorConfirmed ? 'Enabled' : 'Not Enabled' }}
                                    </CardTitle>
                                    <CardDescription>
                                        {{ twoFactorEnabled && twoFactorConfirmed
                                            ? 'Your account is protected with two-factor authentication.'
                                            : 'Two-factor authentication adds an extra layer of security to your account.'
                                        }}
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <template v-if="!twoFactorEnabled">
                                <p class="mb-4 text-muted-foreground">
                                    When two-factor authentication is enabled, you will be prompted for a secure,
                                    random token during authentication. You can retrieve this token from your
                                    phone's authenticator application (Google Authenticator, Authy, etc.).
                                </p>
                                <Button @click="enableTwoFactor" :disabled="enabling">
                                    {{ enabling ? 'Enabling...' : 'Enable Two-Factor Authentication' }}
                                </Button>
                            </template>

                            <template v-else-if="confirming">
                                <div class="space-y-4">
                                    <p class="text-muted-foreground">
                                        Scan the QR code below with your authenticator app, then enter the code to confirm.
                                    </p>

                                    <div v-if="sanitizedQrCode" class="inline-block bg-white p-4 rounded-lg" v-html="sanitizedQrCode" />

                                    <div v-if="setupKey" class="space-y-2">
                                        <p class="font-medium text-sm">Or enter this key manually:</p>
                                        <code class="block bg-muted p-2 rounded font-mono text-sm">{{ setupKey }}</code>
                                    </div>

                                    <form @submit.prevent="confirmTwoFactor" class="space-y-4">
                                        <div class="space-y-2">
                                            <Label for="code">Confirmation Code</Label>
                                            <Input
                                                id="code"
                                                v-model="confirmForm.code"
                                                type="text"
                                                inputmode="numeric"
                                                placeholder="000000"
                                                required
                                                autocomplete="one-time-code"
                                            />
                                            <p v-if="confirmForm.errors.code" class="text-destructive text-sm">
                                                {{ confirmForm.errors.code }}
                                            </p>
                                        </div>
                                        <Button type="submit" :disabled="confirmForm.processing">
                                            {{ confirmForm.processing ? 'Confirming...' : 'Confirm' }}
                                        </Button>
                                    </form>
                                </div>
                            </template>

                            <template v-else>
                                <div class="flex flex-wrap gap-2">
                                    <Button variant="outline" @click="regenerateRecoveryCodes">
                                        <Key class="mr-2 w-4 h-4" />
                                        Regenerate Recovery Codes
                                    </Button>
                                    <Button variant="destructive" @click="disableTwoFactor">
                                        <ShieldOff class="mr-2 w-4 h-4" />
                                        Disable Two-Factor Authentication
                                    </Button>
                                </div>
                            </template>
                        </CardContent>
                    </Card>
                </TabsContent>
            </Tabs>
        </div>
    </AppLayout>
</template>
