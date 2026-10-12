<script setup lang="ts" generic="K extends string">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';

/**
 * Underlined tabs. Items with `href` navigate (server-side tabs); without,
 * they switch the v-model (client-side panels).
 */
defineProps<{ items: { key: K; label: string; href?: string; icon?: Component; count?: number; active?: boolean }[] }>();
const model = defineModel<K>();
</script>

<template>
    <div class="-mx-1 overflow-x-auto border-b border-line">
        <nav class="flex min-w-max gap-1 px-1" role="tablist">
            <component
                :is="item.href ? Link : 'button'"
                v-for="item in items"
                :key="item.key"
                :href="item.href"
                :type="item.href ? undefined : 'button'"
                role="tab"
                :aria-selected="item.active ?? model === item.key"
                :class="[
                    '-mb-px inline-flex items-center gap-2 border-b-2 px-3 py-2.5 text-sm font-medium whitespace-nowrap transition-colors',
                    (item.active ?? model === item.key) ? 'border-brand-600 text-brand-600 dark:text-brand-300' : 'border-transparent text-muted hover:border-line-strong hover:text-ink',
                ]"
                @click="!item.href && (model = item.key)"
            >
                <component :is="item.icon" v-if="item.icon" :size="16" aria-hidden="true" />
                {{ item.label }}
                <span v-if="item.count" class="rounded-full bg-surface-sunken px-1.5 py-px text-xs text-muted tabular-nums">{{ item.count }}</span>
            </component>
        </nav>
    </div>
</template>
