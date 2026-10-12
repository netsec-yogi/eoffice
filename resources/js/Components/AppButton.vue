<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

/**
 * The one button. Hierarchy: primary (one per view) > secondary/outline >
 * ghost (danger-ghost for quiet destructive actions in tables). `icon` adds a leading Lucide icon; with no slot content the button
 * is icon-only and must be given an aria-label.
 */
const props = withDefaults(
    defineProps<{
        variant?: 'primary' | 'secondary' | 'outline' | 'danger' | 'success' | 'ghost' | 'danger-ghost' | 'accent';
        size?: 'sm' | 'md' | 'lg';
        type?: 'button' | 'submit';
        href?: string;
        /** Full page load instead of an Inertia visit (e.g. OAuth redirects). */
        external?: boolean;
        icon?: Component;
        loading?: boolean;
        disabled?: boolean;
    }>(),
    { variant: 'primary', size: 'md', type: 'button' },
);

const slots = defineSlots<{ default?: () => unknown }>();
const iconOnly = computed(() => !!props.icon && !slots.default);

const classes = computed(() => [
    'press inline-flex shrink-0 items-center justify-center gap-1.5 rounded-full font-semibold whitespace-nowrap transition-[color,background-color,box-shadow,transform] duration-150 select-none',
    'focus-visible:outline-2 focus-visible:outline-offset-2 disabled:pointer-events-none disabled:opacity-55',
    iconOnly.value
        ? { sm: 'size-8', md: 'size-10', lg: 'size-11' }[props.size]
        : { sm: 'h-8 px-3.5 text-[13px]', md: 'h-10 px-4.5 text-sm', lg: 'h-11 px-6 text-[15px]' }[props.size],
    {
        primary: 'bg-brand-600 text-white shadow-sm shadow-brand-600/25 hover:bg-deep-700 dark:bg-brand-500 dark:hover:bg-brand-400',
        secondary: 'bg-surface-sunken text-ink hover:bg-line',
        outline: 'bg-surface text-ink ring-1 ring-line-strong ring-inset hover:bg-surface-muted',
        danger: 'bg-red-600 text-white shadow-sm hover:bg-red-700 active:bg-red-800 dark:hover:bg-red-500 dark:active:bg-red-600',
        success: 'bg-emerald-600 text-white shadow-sm hover:bg-emerald-700 active:bg-emerald-800 dark:hover:bg-emerald-500 dark:active:bg-emerald-600',
        ghost: 'text-brand-600 hover:bg-brand-50 hover:text-brand-700 dark:text-brand-300 dark:hover:bg-white/5',
        accent: 'bg-accent-500 text-white shadow-sm shadow-accent-500/30 hover:bg-accent-600',
        'danger-ghost': 'text-red-600 hover:bg-red-50 hover:text-red-700 dark:text-red-400 dark:hover:bg-red-500/10',
    }[props.variant],
]);
const iconSize = computed(() => (props.size === 'sm' ? 15 : 16));
</script>

<template>
    <a v-if="href && external" :href="href" :class="classes">
        <component :is="icon" v-if="icon" :size="iconSize" aria-hidden="true" />
        <slot />
    </a>
    <Link v-else-if="href" :href="href" :class="classes">
        <component :is="icon" v-if="icon" :size="iconSize" aria-hidden="true" />
        <slot />
    </Link>
    <button v-else :type="type" :class="classes" :disabled="disabled || loading" :aria-busy="loading || undefined">
        <LoaderCircle v-if="loading" :size="iconSize" class="animate-spin" aria-hidden="true" />
        <component :is="icon" v-else-if="icon" :size="iconSize" aria-hidden="true" />
        <slot />
    </button>
</template>
