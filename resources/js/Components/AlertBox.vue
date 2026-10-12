<script setup lang="ts">
import { CircleAlert, CircleCheck, Info, TriangleAlert } from 'lucide-vue-next';

withDefaults(defineProps<{ tone?: 'info' | 'success' | 'warning' | 'danger'; title?: string }>(), { tone: 'info' });

const icons = { info: Info, success: CircleCheck, warning: TriangleAlert, danger: CircleAlert };
</script>

<template>
    <div
        :role="tone === 'danger' ? 'alert' : 'status'"
        :class="[
            'flex gap-3 rounded-md p-3.5 text-sm ring-1 ring-inset',
            {
                info: 'bg-brand-50/70 text-brand-900 ring-brand-200',
                success: 'bg-emerald-50/70 text-emerald-900 ring-emerald-200',
                warning: 'bg-amber-50/70 text-amber-900 ring-amber-200',
                danger: 'bg-red-50/70 text-red-900 ring-red-200',
            }[tone],
        ]"
    >
        <component
            :is="icons[tone]"
            :size="18"
            :class="['mt-px shrink-0', { info: 'text-brand-600', success: 'text-emerald-600', warning: 'text-amber-600', danger: 'text-red-600' }[tone]]"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <p v-if="title" class="font-semibold">{{ title }}</p>
            <div :class="{ 'mt-0.5': title }"><slot /></div>
        </div>
    </div>
</template>
