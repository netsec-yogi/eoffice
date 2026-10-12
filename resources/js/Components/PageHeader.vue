<script setup lang="ts">
import AutoBreadcrumbs from './AutoBreadcrumbs.vue';
import Breadcrumbs, { type Crumb } from './Breadcrumbs.vue';

/**
 * Page title row: breadcrumbs, title, description and the page's actions.
 * Breadcrumbs default to the page's place in the navigation.
 */
// Note: no boolean in `breadcrumbs`' type -- Vue would cast an absent prop to false.
defineProps<{ title: string; description?: string; breadcrumbs?: Crumb[]; noBreadcrumbs?: boolean }>();
</script>

<template>
    <div class="mb-6">
        <template v-if="!noBreadcrumbs">
            <Breadcrumbs v-if="breadcrumbs" :items="breadcrumbs" class="mb-2" />
            <AutoBreadcrumbs v-else :title="title" class="mb-2" />
        </template>
        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight text-ink">{{ title }}</h1>
                <p v-if="description" class="mt-1 max-w-3xl text-sm text-muted">{{ description }}</p>
            </div>
            <div v-if="$slots.default" class="flex flex-wrap items-center gap-2"><slot /></div>
        </div>
    </div>
</template>
