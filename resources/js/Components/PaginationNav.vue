<script setup lang="ts">
import type { PaginationLink } from '@/types';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';

defineProps<{ links: PaginationLink[]; from: number | null; to: number | null; total: number }>();

const isPrev = (l: string) => l.includes('&laquo;') || /previous/i.test(l);
const isNext = (l: string) => l.includes('&raquo;') || /next/i.test(l);
</script>

<template>
    <nav v-if="total > 0" class="flex flex-wrap items-center justify-between gap-3 text-[13px]" aria-label="Pagination">
        <p class="text-muted">
            Showing <span class="font-medium text-ink-soft">{{ from?.toLocaleString('en-IN') }}–{{ to?.toLocaleString('en-IN') }}</span> of
            <span class="font-medium text-ink-soft">{{ total.toLocaleString('en-IN') }}</span>
        </p>
        <div v-if="links.length > 3" class="flex flex-wrap gap-1">
            <template v-for="(link, i) in links" :key="i">
                <component
                    :is="link.url ? Link : 'span'"
                    :href="link.url ?? undefined"
                    preserve-scroll
                    :aria-current="link.active ? 'page' : undefined"
                    :aria-label="isPrev(link.label) ? 'Previous page' : isNext(link.label) ? 'Next page' : undefined"
                    :class="[
                        'grid h-8 min-w-8 place-items-center rounded-md px-2 tabular-nums transition-colors',
                        link.active ? 'bg-brand-600 font-medium text-white shadow-sm' : link.url ? 'text-ink-soft hover:bg-surface-sunken' : 'text-subtle',
                    ]"
                >
                    <ChevronLeft v-if="isPrev(link.label)" :size="16" />
                    <ChevronRight v-else-if="isNext(link.label)" :size="16" />
                    <template v-else>{{ link.label }}</template>
                </component>
            </template>
        </div>
    </nav>
</template>
