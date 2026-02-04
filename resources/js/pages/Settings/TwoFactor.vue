<script setup lang="ts">
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { AppLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Shield, ShieldCheck, ShieldOff, QrCode, Key } from 'lucide-vue-next';

const props = defineProps<{
    twoFactorEnabled: boolean;
    twoFactorConfirmed: boolean;
}>();

const enabling = ref(false);
const confirming = ref(false);
const qrCode = ref<string | null>(null);
const setupKey = ref<string | null>(null);
const recoveryCodes = ref<string[] | null>(null);

const confirmForm = useForm({
    code: '',
});

const enableTwoFactor = async () => {
    enabling.value = true;

    try {
        await router.post('/user/two-factor-authentication', {}, {
            preserveScroll: true,
            onSuccess: async () => {
                // Fetch QR code and setup key
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

            // Fetch recovery codes
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
</script>

<template>
    <AppLayout title="Two-Factor Authentication">
        <div class="space-y-4 max-w-2xl">
            <div>
                <h1 class="text-xl font-semibold">Two-Factor Authentication</h1>
                <p class="text-sm text-muted-foreground">Add additional security to your account</p>
            </div>

            <!-- Status Card -->
            <Card>
                <CardHeader>
                    <div class="flex items-center gap-3">
                        <div :class="[
                            'p-2 rounded-lg',
                            twoFactorEnabled && twoFactorConfirmed ? 'bg-green-100 dark:bg-green-900' : 'bg-muted'
                        ]">
                            <ShieldCheck
                                v-if="twoFactorEnabled && twoFactorConfirmed"
                                class="h-6 w-6 text-green-600"
                            />
                            <Shield v-else class="h-6 w-6 text-muted-foreground" />
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
                        <p class="text-muted-foreground mb-4">
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

                            <div v-if="qrCode" class="p-4 bg-white rounded-lg inline-block" v-html="qrCode" />

                            <div v-if="setupKey" class="space-y-2">
                                <p class="text-sm font-medium">Or enter this key manually:</p>
                                <code class="block p-2 bg-muted rounded font-mono text-sm">{{ setupKey }}</code>
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
                                    <p v-if="confirmForm.errors.code" class="text-sm text-destructive">
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
                        <div class="space-y-4">
                            <Button variant="outline" @click="regenerateRecoveryCodes">
                                <Key class="h-4 w-4 mr-2" />
                                Regenerate Recovery Codes
                            </Button>
                            <Button variant="destructive" @click="disableTwoFactor">
                                <ShieldOff class="h-4 w-4 mr-2" />
                                Disable Two-Factor Authentication
                            </Button>
                        </div>
                    </template>
                </CardContent>
            </Card>

            <!-- Recovery Codes Alert -->
            <Alert v-if="recoveryCodes" class="border-yellow-500 bg-yellow-50 dark:bg-yellow-950">
                <Key class="h-4 w-4 text-yellow-600" />
                <AlertTitle class="text-yellow-800 dark:text-yellow-200">Recovery Codes</AlertTitle>
                <AlertDescription class="mt-2">
                    <p class="text-yellow-700 dark:text-yellow-300 mb-4">
                        Store these recovery codes in a secure location. They can be used to recover
                        access to your account if you lose your authenticator device.
                    </p>
                    <div class="grid grid-cols-2 gap-2 mb-4">
                        <code
                            v-for="code in recoveryCodes"
                            :key="code"
                            class="p-2 bg-white dark:bg-gray-900 rounded border font-mono text-sm text-center"
                        >
                            {{ code }}
                        </code>
                    </div>
                    <Button variant="ghost" size="sm" @click="dismissRecoveryCodes">
                        I've saved these codes
                    </Button>
                </AlertDescription>
            </Alert>
        </div>
    </AppLayout>
</template>
