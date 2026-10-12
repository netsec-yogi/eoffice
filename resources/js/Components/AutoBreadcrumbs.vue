<script setup lang="ts">
import { buildNavigation, currentTrail } from '@/lib/navigation';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import Breadcrumbs, { type Crumb } from './Breadcrumbs.vue';

/** Breadcrumbs derived from where the current route sits in the navigation. */
const props = defineProps<{ title: string }>();
const page = usePage();

const crumbs = computed<Crumb[]>(() => {
    const user = page.props.auth.user;
    if (!user || route().current('dashboard')) return [];
    const trail = currentTrail(buildNavigation(user));
    if (trail.length === 0) return [];
    const items: Crumb[] = trail.map((c) => ({ label: c.label, href: c.route ? route(c.route) : undefined }));
    const lastRoute = trail[trail.length - 1]!.route;
    if (!route().current(lastRoute) && items[items.length - 1]!.label.toLowerCase() !== props.title.toLowerCase()) {
        items.push({ label: props.title });
    }
    return items;
});
</script>

<template>
    <Breadcrumbs v-if="crumbs.length" :items="crumbs" />
</template>
