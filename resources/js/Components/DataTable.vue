<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, onUpdated, ref } from 'vue';
import EmptyState from './EmptyState.vue';

/**
 * Card-wrapped table. Put a plain <table> in the default slot: the
 * `.data-table` styles give headers, spacing and hover rows. The toolbar
 * slot holds search and filters; the footer holds pagination. While an
 * Inertia visit is in flight (filtering, paging) rows dim and a progress
 * bar shows -- unless `loading` is controlled explicitly.
 */
const props = defineProps<{ empty?: boolean; emptyTitle?: string; emptyDescription?: string; loading?: boolean; title?: string; description?: string }>();

const navigating = ref(false);
const offStart = router.on('start', () => (navigating.value = true));
const offFinish = router.on('finish', () => (navigating.value = false));
onBeforeUnmount(() => {
    offStart();
    offFinish();
});
const busy = () => props.loading ?? navigating.value;

// Phones show each row as a card; every cell gets its column header as a label (see .data-table in app.css).
const root = ref<HTMLElement>();
function labelCells() {
    root.value?.querySelectorAll('table.data-table').forEach((table) => {
        const headers = [...table.querySelectorAll('thead th')].map((th) => (th as HTMLElement).innerText.trim());
        table.querySelectorAll('tbody tr').forEach((tr) => [...tr.children].forEach((td, i) => td.setAttribute('data-label', headers[i] ?? '')));
    });
}
onMounted(labelCells);
onUpdated(labelCells);
</script>

<template>
    <section ref="root" class="card overflow-hidden">
        <header v-if="title || $slots.toolbar" class="flex flex-wrap items-center justify-between gap-3 border-b border-line-soft px-4 py-3">
            <div v-if="title">
                <h2 class="text-[15px] font-semibold text-ink">{{ title }}</h2>
                <p v-if="description" class="text-[13px] text-muted">{{ description }}</p>
            </div>
            <div class="flex flex-1 flex-wrap items-center justify-end gap-2"><slot name="toolbar" /></div>
        </header>
        <div class="relative">
            <div v-if="busy()" class="absolute inset-x-0 top-0 z-10 h-0.5 overflow-hidden bg-brand-100" role="progressbar" aria-label="Loading">
                <div class="h-full w-1/3 animate-[loading_1s_ease-in-out_infinite] bg-brand-500" />
            </div>
            <EmptyState v-if="empty" compact :title="emptyTitle ?? 'Nothing here yet'" :description="emptyDescription"><slot name="empty" /></EmptyState>
            <div v-else :class="['overflow-x-auto transition-opacity', busy() && 'opacity-60']">
                <slot />
            </div>
        </div>
        <footer v-if="$slots.footer" class="border-t border-line-soft px-4 py-3"><slot name="footer" /></footer>
    </section>
</template>
