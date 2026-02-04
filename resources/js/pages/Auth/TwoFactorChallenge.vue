<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { AuthLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const recovery = ref(false);

const form = useForm({
    code: '',
    recovery_code: '',
});

const submit = () => {
    form.post('/two-factor-challenge', {
        onFinish: () => form.reset(),
    });
};

const toggleRecovery = () => {
    recovery.value = !recovery.value;
    form.reset();
};
</script>

<template>
    <AuthLayout title="Two-Factor Authentication">
        <Card>
            <CardHeader class="text-center">
                <CardTitle class="text-2xl">Two-Factor Authentication</CardTitle>
                <CardDescription>
                    <template v-if="!recovery">
                        Please enter the authentication code from your authenticator app.
                    </template>
                    <template v-else>
                        Please enter one of your recovery codes.
                    </template>
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="submit" class="space-y-4">
                    <div v-if="!recovery" class="space-y-2">
                        <Label for="code">Authentication Code</Label>
                        <Input
                            id="code"
                            v-model="form.code"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            required
                            autofocus
                            autocomplete="one-time-code"
                            placeholder="000000"
                        />
                        <p v-if="form.errors.code" class="text-sm text-destructive">
                            {{ form.errors.code }}
                        </p>
                    </div>

                    <div v-else class="space-y-2">
                        <Label for="recovery_code">Recovery Code</Label>
                        <Input
                            id="recovery_code"
                            v-model="form.recovery_code"
                            type="text"
                            required
                            autofocus
                            autocomplete="one-time-code"
                        />
                        <p v-if="form.errors.recovery_code" class="text-sm text-destructive">
                            {{ form.errors.recovery_code }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-2">
                        <Button type="submit" class="w-full" :disabled="form.processing">
                            {{ form.processing ? 'Verifying...' : 'Verify' }}
                        </Button>
                        <Button type="button" variant="ghost" @click="toggleRecovery">
                            <template v-if="!recovery">Use a recovery code</template>
                            <template v-else>Use an authentication code</template>
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    </AuthLayout>
</template>
