<script setup lang="ts">
import { Moon, Sun, Palette } from 'lucide-vue-next';
import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { useTheme } from '@/composables/useTheme';
import ThemePicker from '@/components/ThemePicker.vue';

const { resolvedTheme, toggleTheme } = useTheme();
</script>

<template>
    <div class="flex items-center gap-1">
        <Button variant="ghost" size="icon" class="h-9 w-9" @click="toggleTheme">
            <Sun v-if="resolvedTheme === 'dark'" class="h-4 w-4" />
            <Moon v-else class="h-4 w-4" />
            <span class="sr-only">Toggle theme</span>
        </Button>

        <Sheet>
            <SheetTrigger as-child>
                <Button variant="ghost" size="icon" class="h-9 w-9">
                    <Palette class="h-4 w-4" />
                    <span class="sr-only">Customize theme</span>
                </Button>
            </SheetTrigger>
            <SheetContent side="right" class="overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>Theme Settings</SheetTitle>
                </SheetHeader>
                <ThemePicker />
            </SheetContent>
        </Sheet>
    </div>
</template>
