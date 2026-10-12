<script setup lang="ts">
import { X } from 'lucide-vue-next';
import { nextTick, ref, watch } from 'vue';

/**
 * Modal on the native <dialog>: focus trap, Escape and inert background
 * for free. Header with close button, scrollable body, footer for actions.
 */
const props = withDefaults(defineProps<{ show: boolean; title: string; description?: string; size?: 'sm' | 'md' | 'lg' | 'xl' }>(), { size: 'md' });
const emit = defineEmits<{ close: [] }>();
const dialog = ref<HTMLDialogElement>();

watch(
    () => props.show,
    async (open) => {
        await nextTick();
        if (open && !dialog.value?.open) dialog.value?.showModal();
        if (!open && dialog.value?.open) dialog.value?.close();
    },
    { immediate: true },
);

// Clicking the backdrop (the dialog element itself, outside the panel) closes.
const onClick = (e: MouseEvent) => {
    if (e.target === dialog.value) emit('close');
};
</script>

<template>
    <dialog
        ref="dialog"
        :class="[
            // Phones: a bottom sheet. Larger screens: a centred dialog.
            'mx-0 mt-auto mb-0 max-h-[92dvh] w-full max-w-none overflow-hidden rounded-t-[1.75rem] bg-surface p-0 text-ink-soft shadow-pop backdrop:bg-slate-950/50 backdrop:backdrop-blur-sm',
            'sm:m-auto sm:max-h-[calc(100dvh-2rem)] sm:w-[calc(100%-2rem)] sm:rounded-[var(--radius-card)]',
            { sm: 'sm:max-w-md', md: 'sm:max-w-lg', lg: 'sm:max-w-2xl', xl: 'sm:max-w-4xl' }[size],
        ]"
        :aria-label="title"
        @close="emit('close')"
        @cancel.prevent="emit('close')"
        @click="onClick"
    >
        <div v-if="show" class="flex max-h-[92dvh] flex-col sm:max-h-[calc(100dvh-2rem)]">
            <span class="mx-auto mt-2.5 h-1 w-10 rounded-full bg-line-strong sm:hidden" aria-hidden="true" />
            <header class="flex items-start justify-between gap-4 px-5 pt-4 pb-2 sm:pt-5">
                <div>
                    <h2 class="text-lg font-bold text-ink">{{ title }}</h2>
                    <p v-if="description" class="mt-0.5 text-[13px] text-muted">{{ description }}</p>
                </div>
                <button type="button" class="-m-1 rounded-full p-1.5 text-subtle hover:bg-surface-sunken hover:text-ink" aria-label="Close" @click="emit('close')">
                    <X :size="18" />
                </button>
            </header>
            <div class="overflow-y-auto px-5 py-4"><slot /></div>
            <footer v-if="$slots.footer" class="flex flex-wrap justify-end gap-2 px-5 pt-2 pb-5"><slot name="footer" /></footer>
        </div>
    </dialog>
</template>
