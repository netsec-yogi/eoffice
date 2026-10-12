<script setup lang="ts">
import { dismiss, toast, toasts } from '@/lib/toast';
import { usePage } from '@inertiajs/vue3';
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-vue-next';
import { watch } from 'vue';

const page = usePage();

watch(
    () => page.props.flash,
    (flash) => {
        if (flash?.success) toast(flash.success, 'success');
        if (flash?.warning) toast(flash.warning, 'warning');
        if (flash?.error) toast(flash.error, 'error');
    },
    { immediate: true, deep: true },
);

const icon = { success: CircleCheck, info: Info, warning: TriangleAlert, error: CircleAlert };
const accent = { success: 'text-emerald-600', info: 'text-brand-600', warning: 'text-amber-600', error: 'text-red-600' };
const bar = { success: 'bg-emerald-500', info: 'bg-brand-500', warning: 'bg-amber-500', error: 'bg-red-500' };
</script>

<template>
    <div class="pointer-events-none fixed inset-x-0 top-[4.5rem] z-[60] flex flex-col items-center gap-2 px-4 sm:right-4 sm:left-auto sm:items-end" aria-live="polite">
        <TransitionGroup
            enter-from-class="opacity-0 translate-x-4"
            enter-active-class="transition duration-200 ease-out"
            leave-to-class="opacity-0"
            leave-active-class="transition duration-150"
        >
            <div
                v-for="t in toasts"
                :key="t.id"
                class="pointer-events-auto relative flex w-full max-w-sm items-start gap-3 overflow-hidden rounded-md bg-surface py-3 pr-3 pl-4 text-sm text-ink-soft shadow-pop ring-1 ring-line"
                :role="t.tone === 'error' ? 'alert' : 'status'"
            >
                <span :class="['absolute inset-y-0 left-0 w-1', bar[t.tone]]" aria-hidden="true" />
                <component :is="icon[t.tone]" :size="18" :class="['mt-px shrink-0', accent[t.tone]]" aria-hidden="true" />
                <span class="flex-1">{{ t.text }}</span>
                <button type="button" class="rounded p-0.5 text-subtle hover:text-ink" aria-label="Dismiss" @click="dismiss(t.id)"><X :size="16" /></button>
            </div>
        </TransitionGroup>
    </div>
</template>
