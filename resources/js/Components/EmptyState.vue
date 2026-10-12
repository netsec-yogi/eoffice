<script setup lang="ts">
import { Inbox } from 'lucide-vue-next';
import type { Component } from 'vue';

/** What's missing, why, and (in the slot) the action that fixes it. A small illustration sets the tone. */
defineProps<{ title: string; description?: string; icon?: Component; compact?: boolean }>();
</script>

<template>
    <div :class="['flex flex-col items-center text-center', compact ? 'px-4 py-8' : 'card px-6 py-14']">
        <!-- Illustration: soft blobs behind the icon. Decorative. -->
        <span class="relative grid size-20 place-items-center" aria-hidden="true">
            <span class="absolute inset-1 rotate-6 rounded-[1.75rem] bg-gradient-to-br from-brand-100 to-accent-50 dark:from-brand-500/20 dark:to-accent-500/10" />
            <span class="absolute -top-1 right-1 size-3 rounded-full bg-accent-400/70" />
            <span class="absolute bottom-2 -left-1 size-2 rounded-full bg-brand-400/70" />
            <span class="relative grid size-12 place-items-center rounded-2xl bg-surface text-brand-600 shadow-card dark:text-brand-300">
                <component :is="icon ?? Inbox" :size="22" />
            </span>
        </span>
        <p class="mt-4 text-base font-bold text-ink">{{ title }}</p>
        <p v-if="description" class="mt-1 max-w-sm text-sm text-muted">{{ description }}</p>
        <div v-if="$slots.default" class="mt-5 flex flex-wrap justify-center gap-2"><slot /></div>
    </div>
</template>
