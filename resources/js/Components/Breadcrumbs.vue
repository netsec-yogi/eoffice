<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronRight, House } from 'lucide-vue-next';

export interface Crumb {
    label: string;
    href?: string;
}

defineProps<{ items: Crumb[] }>();
</script>

<template>
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-[13px] text-muted">
            <li class="flex items-center">
                <Link :href="route('dashboard')" class="hover:text-brand-600" aria-label="Home"><House :size="14" aria-hidden="true" /></Link>
            </li>
            <li v-for="(c, i) in items" :key="i" class="flex items-center gap-1">
                <ChevronRight :size="14" class="text-subtle" aria-hidden="true" />
                <Link v-if="c.href && i < items.length - 1" :href="c.href" class="hover:text-brand-600">{{ c.label }}</Link>
                <span v-else :aria-current="i === items.length - 1 ? 'page' : undefined" class="text-ink-soft">{{ c.label }}</span>
            </li>
        </ol>
    </nav>
</template>
