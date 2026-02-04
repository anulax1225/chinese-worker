<script setup lang="ts">
import { useForm, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { AuthLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';

const props = defineProps<{
    status?: string;
}>();

const form = useForm({});

const verificationLinkSent = computed(() => props.status === 'verification-link-sent');

const submit = () => {
    form.post('/email/verification-notification');
};

const logout = () => {
    router.post('/logout');
};
</script>

<template>
    <AuthLayout title="Verify Email">
        <Card>
            <CardHeader class="text-center">
                <CardTitle class="text-2xl">Verify Your Email</CardTitle>
                <CardDescription>
                    Thanks for signing up! Before getting started, please verify your email
                    address by clicking on the link we sent you. If you didn't receive the email,
                    we'll gladly send you another.
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
                <Alert v-if="verificationLinkSent">
                    <AlertDescription>
                        A new verification link has been sent to your email address.
                    </AlertDescription>
                </Alert>

                <div class="flex flex-col gap-2">
                    <Button @click="submit" :disabled="form.processing" class="w-full">
                        {{ form.processing ? 'Sending...' : 'Resend Verification Email' }}
                    </Button>
                    <Button variant="ghost" @click="logout" class="w-full">
                        Log Out
                    </Button>
                </div>
            </CardContent>
        </Card>
    </AuthLayout>
</template>
