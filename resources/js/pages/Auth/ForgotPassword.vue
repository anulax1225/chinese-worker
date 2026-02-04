<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { AuthLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post('/forgot-password');
};
</script>

<template>
    <AuthLayout title="Forgot Password">
        <Card>
            <CardHeader class="text-center">
                <CardTitle class="text-2xl">Forgot Password</CardTitle>
                <CardDescription>
                    Enter your email address and we'll send you a password reset link.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Alert v-if="status" class="mb-4">
                    <AlertDescription>{{ status }}</AlertDescription>
                </Alert>

                <form @submit.prevent="submit" class="space-y-4">
                    <div class="space-y-2">
                        <Label for="email">Email</Label>
                        <Input
                            id="email"
                            v-model="form.email"
                            type="email"
                            placeholder="you@example.com"
                            required
                            autofocus
                            autocomplete="username"
                        />
                        <p v-if="form.errors.email" class="text-sm text-destructive">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Sending...' : 'Send Reset Link' }}
                    </Button>
                </form>
            </CardContent>
            <CardFooter class="justify-center">
                <Link href="/login" class="text-sm text-muted-foreground hover:text-primary">
                    Back to sign in
                </Link>
            </CardFooter>
        </Card>
    </AuthLayout>
</template>
