<script setup lang="ts">
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { ChevronRight, FilePlus2, Search } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Workflow { key: string; name: string; description: string | null; category: string; department: string | null }
const props = defineProps<{ workflows: Workflow[] }>();

const q = ref('');
const groups = computed(() => {
    const term = q.value.trim().toLowerCase();
    const out: Record<string, Workflow[]> = {};
    props.workflows
        .filter((w) => !term || `${w.name} ${w.description ?? ''} ${w.department ?? ''}`.toLowerCase().includes(term))
        .forEach((w) => (out[w.category] ??= []).push(w));
    return Object.entries(out).sort(([a], [b]) => a.localeCompare(b));
});
const label = (c: string) => c.replace(/_/g, ' ');
</script>

<template>
    <AppLayout title="New request">
        <PageHeader title="New request" description="Choose what you need. Only the services you are eligible for are listed." />
        <div class="mb-6 max-w-md"><TextInput v-model="q" :icon="Search" placeholder="Search services" aria-label="Search services" /></div>

        <EmptyState v-if="!groups.length" :icon="FilePlus2" title="No services found" :description="workflows.length ? 'Try another search.' : 'No workflows have been published yet.'" />

        <section v-for="[category, list] in groups" :key="category" class="mb-8">
            <h2 class="mb-3 text-xs font-semibold tracking-wider text-subtle uppercase">{{ label(category) }}</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link v-for="w in list" :key="w.key" :href="route('requests.create', w.key)" class="card card-hover group flex items-start gap-3 p-4">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold text-ink group-hover:text-brand-700">{{ w.name }}</p>
                        <p v-if="w.description" class="mt-1 line-clamp-2 text-sm text-muted">{{ w.description }}</p>
                        <p v-if="w.department" class="mt-2 text-xs text-subtle">{{ w.department }}</p>
                    </div>
                    <ChevronRight :size="18" class="mt-0.5 shrink-0 text-subtle group-hover:text-brand-600" aria-hidden="true" />
                </Link>
            </div>
        </section>
    </AppLayout>
</template>
