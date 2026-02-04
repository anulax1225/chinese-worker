<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { AuthLayout } from '@/layouts';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post('/user/confirm-password', {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <AuthLayout title="Confirm Password">
        <Card>
            <CardHeader class="text-center">
                <CardTitle class="text-2xl">Confirm Password</CardTitle>
                <CardDescription>
                    This is a secure area. Please confirm your password before continuing.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="space-y-2">
                        <Label for="password">Password</Label>
                        <Input
                            id="password"
                            v-model="form.password"
                            type="password"
                            required
                            autofocus
                            autocomplete="current-password"
                        />
                        <p v-if="form.errors.password" class="text-sm text-destructive">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <Button type="submit" class="w-full" :disabled="form.processing">
                        {{ form.processing ? 'Confirming...' : 'Confirm' }}
                    </Button>
                </form>
            </CardContent>
        </Card>
    </AuthLayout>
</template>
