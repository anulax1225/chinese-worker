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
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post('/register', {
        preserveScroll: true,
    });
};
</script>

<template>
    <GuestLayout title="Register">
        <Card>
            <CardHeader>
                <CardTitle>Create an account</CardTitle>
                <CardDescription>Enter your details to get started</CardDescription>
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
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            v-model="form.name"
                            type="text"
                            placeholder="John Doe"
                            required
                            autocomplete="name"
                        />
                    </div>

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
                            autocomplete="new-password"
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="password_confirmation">Confirm Password</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            required
                            autocomplete="new-password"
                        />
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Creating account...' : 'Create account' }}
                    </Button>

                    <div class="text-center text-sm text-muted-foreground">
                        Already have an account?
                        <a href="/login" class="font-medium text-primary hover:underline">Log in</a>
                    </div>
                </form>
            </CardContent>
        </Card>
    </GuestLayout>
</template>
