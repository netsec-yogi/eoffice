<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowDownRight, ArrowUpRight } from 'lucide-vue-next';
import type { Component } from 'vue';

/**
 * KPI tile: icon, label, big number, optional trend (% vs previous period)
 * and hint. With `href` the whole tile links to the detail page.
 */
defineProps<{
    label: string;
    value: number | string;
    icon?: Component;
    tone?: 'default' | 'brand' | 'success' | 'warning' | 'danger' | 'accent';
    trend?: number | null;
    trendLabel?: string;
    hint?: string;
    href?: string;
    /** Colour the number itself: something needs action. */
    alert?: boolean;
}>();

const iconTone = {
    default: 'bg-surface-sunken text-muted',
    brand: 'bg-brand-50 text-brand-600 dark:bg-brand-500/15 dark:text-brand-300',
    success: 'bg-emerald-50 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
    danger: 'bg-red-50 text-red-600 dark:bg-red-500/15 dark:text-red-300',
    accent: 'bg-accent-400/15 text-accent-600',
};
</script>

<template>
    <component :is="href ? Link : 'div'" :href="href" :class="['card block p-5', href && 'card-hover']">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate text-[13px] font-medium text-muted">{{ label }}</p>
                <p :class="['mt-1.5 text-2xl font-semibold tracking-tight tabular-nums', alert && tone === 'danger' ? 'text-red-600' : alert || (tone === 'warning' && !icon) ? 'text-amber-600' : 'text-ink']">
                    {{ typeof value === 'number' ? value.toLocaleString('en-IN') : value }}
                </p>
            </div>
            <span v-if="icon" :class="['grid size-11 shrink-0 place-items-center rounded-full', iconTone[tone ?? 'brand']]">
                <component :is="icon" :size="20" aria-hidden="true" />
            </span>
        </div>
        <p v-if="trend != null || hint" class="mt-3 flex flex-wrap items-center gap-x-1.5 text-xs text-muted">
            <span
                v-if="trend != null"
                :class="['inline-flex items-center gap-0.5 font-semibold', trend >= 0 ? 'text-emerald-600' : 'text-red-600']"
                :aria-label="`${trend >= 0 ? 'Up' : 'Down'} ${Math.abs(trend)} percent`"
            >
                <component :is="trend >= 0 ? ArrowUpRight : ArrowDownRight" :size="14" aria-hidden="true" />{{ Math.abs(trend) }}%
            </span>
            <span v-if="trend != null">{{ trendLabel ?? 'vs last month' }}</span>
            <span v-if="hint && trend != null" aria-hidden="true">·</span>
            <span v-if="hint">{{ hint }}</span>
        </p>
    </component>
</template>
