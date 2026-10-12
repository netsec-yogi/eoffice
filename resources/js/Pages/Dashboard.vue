<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatCard from '@/Components/StatCard.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { currentStep, date, statusLabel, when, type InstanceSummary } from '@/Workflow/format';
import OfficialDocumentList from '@/Pages/OfficialDocuments/OfficialDocumentList.vue';
import type { OfficialDocumentRow } from '@/Pages/OfficialDocuments/types';
import { Link, usePage } from '@inertiajs/vue3';
import { AlarmClock, CircleCheck, FilePen, FilePlus2, FolderOpen, Inbox, Undo2, UserCheck } from 'lucide-vue-next';
import { computed } from 'vue';

defineProps<{
    officialDocuments: OfficialDocumentRow[];
    stats: {
        counts: Record<string, number>;
        recent: InstanceSummary[];
        due: { id: number; name: string; due_at: string | null; overdue: boolean; instance: { id: number; reference: string; title: string } }[];
    };
}>();
const user = computed(() => usePage().props.auth.user!);
</script>

<template>
    <AppLayout title="Dashboard">
        <PageHeader :title="`Welcome, ${user.name.split(' ')[0]}`" description="Your requests, approvals and deadlines.">
            <AppButton :icon="FilePlus2" :href="route('requests.catalogue')">New request</AppButton>
        </PageHeader>

        <div class="mb-8 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <StatCard label="Waiting for my decision" :value="stats.counts.pending ?? 0" :icon="Inbox" tone="brand" :href="route('tasks.index')" />
            <StatCard label="Overdue with me" :value="stats.counts.overdue ?? 0" :icon="AlarmClock" :tone="stats.counts.overdue ? 'danger' : 'default'" :alert="!!stats.counts.overdue" :href="route('tasks.index', { tab: 'overdue' })" />
            <StatCard label="Returned to me" :value="stats.counts.returned ?? 0" :icon="Undo2" :tone="stats.counts.returned ? 'warning' : 'default'" :href="route('requests.index', { status: 'returned' })" />
            <StatCard label="My open requests" :value="stats.counts.open ?? 0" :icon="FolderOpen" :href="route('requests.index', { status: 'in_progress' })" />
            <StatCard v-if="stats.counts.delegated" label="Delegated to me" :value="stats.counts.delegated" :icon="UserCheck" tone="warning" :href="route('tasks.index', { tab: 'delegated' })" />
            <StatCard v-if="stats.counts.drafts" label="Drafts" :value="stats.counts.drafts" :icon="FilePen" :href="route('requests.index', { status: 'draft' })" />
            <StatCard label="Approved in the last 30 days" :value="stats.counts.completed_30d ?? 0" :icon="CircleCheck" tone="success" />
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-ink">Needs my decision</h2>
                    <Link :href="route('tasks.index')" class="text-sm font-medium text-brand-700 hover:underline">Inbox</Link>
                </div>
                <EmptyState v-if="!stats.due.length" compact title="Nothing waiting for you" />
                <ul v-else class="divide-y divide-line-soft">
                    <li v-for="t in stats.due" :key="t.id" class="py-2.5">
                        <Link :href="route('requests.show', { instance: t.instance.id, task: t.id })" class="font-medium text-ink hover:text-brand-700">{{ t.instance.title }}</Link>
                        <p class="text-xs text-muted">
                            {{ t.instance.reference }} · {{ t.name }} ·
                            <span :class="t.overdue && 'font-semibold text-red-700'">{{ t.due_at ? `due ${date(t.due_at)}` : 'no deadline' }}</span>
                        </p>
                    </li>
                </ul>
            </section>
            <section class="card p-5">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-ink">My recent requests</h2>
                    <Link :href="route('requests.index')" class="text-sm font-medium text-brand-700 hover:underline">All</Link>
                </div>
                <EmptyState v-if="!stats.recent.length" compact title="You have not raised any requests" />
                <ul v-else class="divide-y divide-line-soft">
                    <li v-for="i in stats.recent" :key="i.id" class="flex items-start justify-between gap-3 py-2.5">
                        <div class="min-w-0">
                            <Link :href="route('requests.show', i.id)" class="font-medium text-ink hover:text-brand-700">{{ i.title }}</Link>
                            <p class="truncate text-xs text-muted">{{ i.reference }} · {{ currentStep(i) }} · {{ when(i.updated_at) }}</p>
                        </div>
                        <StatusBadge :status="i.status" :label="statusLabel(i.status)" />
                    </li>
                </ul>
            </section>
            <section v-if="officialDocuments.length" class="card p-5 lg:col-span-2">
                <div class="mb-3 flex items-center justify-between">
                    <h2 class="font-semibold text-ink">Official documents</h2>
                    <Link :href="route('official-documents.index')" class="text-sm font-medium text-brand-700 hover:underline">All</Link>
                </div>
                <OfficialDocumentList :documents="officialDocuments" show-reference />
            </section>
        </div>
    </AppLayout>
</template>
