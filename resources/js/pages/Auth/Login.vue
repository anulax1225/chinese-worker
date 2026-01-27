<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import GuestLayout from '@/layouts/GuestLayout.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { AlertCircle } from 'lucide-vue-next';

interface Props {
    errors?: Record<string, string>;
}

const props = defineProps<Props>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post('/login', {
        preserveScroll: true,
    });
};
</script>

<template>
    <GuestLayout title="Login">
        <Card>
            <CardHeader>
                <CardTitle>Welcome back</CardTitle>
                <CardDescription>Enter your credentials to access your account</CardDescription>
            </CardHeader>
            <CardContent>
                <Alert v-if="errors && Object.keys(errors).length > 0" variant="destructive" class="mb-4">
                    <AlertCircle class="h-4 w-4" />
                    <AlertDescription>
                        {{ Object.values(errors)[0] }}
                    </AlertDescription>
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
                            autocomplete="email"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autocomplete="current-password"
                        />
                    </div>

                    <div class="flex items-center justify-between">
                        <div class="flex items-center space-x-2">
                            <input
                                id="remember"
                                v-model="form.remember"
                                type="checkbox"
                                class="h-4 w-4 rounded border-input"
                            />
                            <Label for="remember" class="text-sm font-normal">Remember me</Label>
                        </div>
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Logging in...' : 'Log in' }}
                    </Button>

                    <div class="text-center text-sm text-muted-foreground">
                        Don't have an account?
                        <a href="/register" class="font-medium text-primary hover:underline">Register</a>
                    </div>
                </form>
            </CardContent>
        </Card>
    </GuestLayout>
</template>
