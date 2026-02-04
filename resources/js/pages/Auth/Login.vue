<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { AuthLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Switch } from '@/components/ui/switch';

defineProps<{
    canResetPassword: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthLayout title="Sign In">
        <Card>
            <CardHeader class="text-center">
                <CardTitle class="text-2xl">Sign In</CardTitle>
                <CardDescription>Enter your credentials to access your account</CardDescription>
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

                    <div class="space-y-2">
                        <div class="flex items-center justify-between">
                            <Label for="password">Password</Label>
                            <Link
                                v-if="canResetPassword"
                                href="/forgot-password"
                                class="text-sm text-muted-foreground hover:text-primary"
                            >
                                Forgot password?
                            </Link>
                        </div>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autocomplete="current-password"
                        />
                        <p v-if="form.errors.password" class="text-sm text-destructive">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <div class="flex items-center gap-2">
                        <Switch id="remember" v-model:checked="form.remember" />
                        <Label for="remember" class="text-sm font-normal">Remember me</Label>
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Signing in...' : 'Sign In' }}
                    </Button>
                </form>
            </CardContent>
            <CardFooter class="justify-center">
                <p class="text-sm text-muted-foreground">
                    Don't have an account?
                    <Link href="/register" class="text-primary hover:underline">Sign up</Link>
                </p>
            </CardFooter>
        </Card>
    </AuthLayout>
</template>
