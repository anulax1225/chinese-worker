<script setup lang="ts">
import type { DialogContentEmits, DialogContentProps } from 'reka-ui';
import type { HTMLAttributes } from 'vue';
import { reactiveOmit } from '@vueuse/core';
import { X } from 'lucide-vue-next';
import {
    DialogClose,
    DialogContent,
    DialogOverlay,
    DialogPortal,
    useForwardPropsEmits,
} from 'reka-ui';
import { cn } from '@/lib/utils';
import { computed } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type SheetSide = 'top' | 'right' | 'bottom' | 'left';

interface SheetContentProps extends DialogContentProps {
    class?: HTMLAttributes['class'];
    side?: SheetSide;
    showCloseButton?: boolean;
}

const props = withDefaults(defineProps<SheetContentProps>(), {
    side: 'right',
    showCloseButton: true,
});

const emits = defineEmits<DialogContentEmits>();

const delegatedProps = reactiveOmit(props, 'class', 'side', 'showCloseButton');
const forwarded = useForwardPropsEmits(delegatedProps, emits);

const sideClasses = computed(() => {
    const classes: Record<SheetSide, string> = {
        top: 'inset-x-0 top-0 border-b data-[state=closed]:slide-out-to-top data-[state=open]:slide-in-from-top',
        right: 'inset-y-0 right-0 h-full w-full sm:w-[480px] border-l data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right',
        bottom: 'inset-x-0 bottom-0 border-t data-[state=closed]:slide-out-to-bottom data-[state=open]:slide-in-from-bottom',
        left: 'inset-y-0 left-0 h-full w-full sm:w-[480px] border-r data-[state=closed]:slide-out-to-left data-[state=open]:slide-in-from-left',
    };
    return classes[props.side];
});
</script>

<template>
    <DialogPortal>
        <DialogOverlay
            data-slot="sheet-overlay"
            class="data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 fixed inset-0 z-50 bg-black/80"
        />
        <DialogContent
            data-slot="sheet-content"
            v-bind="{ ...$attrs, ...forwarded }"
            :class="
                cn(
                    'bg-background fixed z-50 flex flex-col shadow-lg transition ease-in-out',
                    'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=open]:duration-300 data-[state=closed]:duration-200',
                    sideClasses,
                    props.class
                )
            "
        >
            <slot />

            <DialogClose
                v-if="showCloseButton"
                data-slot="sheet-close"
                class="ring-offset-background focus:ring-ring data-[state=open]:bg-accent absolute top-4 right-4 rounded-sm opacity-70 transition-opacity hover:opacity-100 focus:ring-2 focus:ring-offset-2 focus:outline-hidden disabled:pointer-events-none"
            >
                <X class="h-4 w-4" />
                <span class="sr-only">Close</span>
            </DialogClose>
        </DialogContent>
    </DialogPortal>
</template>
