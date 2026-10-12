<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import EmptyState from '@/Components/EmptyState.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { when } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { Bell, CheckCheck } from 'lucide-vue-next';

interface M { id: number; subject: string | null; body: string; link: string | null; read: boolean; at: string }
defineProps<{ messages: Paginated<M> }>();
const readAll = () => router.post(route('notifications.read'), {}, { preserveScroll: true });
const open = (m: M) => !m.read && router.post(route('notifications.read'), { ids: [m.id] }, { preserveScroll: true, preserveState: true });
</script>

<template>
    <AppLayout title="Notifications">
        <PageHeader title="Notifications">
            <AppButton variant="outline" size="sm" :icon="CheckCheck" @click="readAll">Mark all read</AppButton>
        </PageHeader>
        <EmptyState v-if="!messages.data.length" :icon="Bell" title="No notifications yet" />
        <ul v-else class="card divide-y divide-line-soft">
            <li v-for="m in messages.data" :key="m.id" :class="['flex gap-3 px-5 py-4', !m.read && 'bg-brand-50/50 dark:bg-brand-500/5']">
                <span :class="['mt-1.5 size-2 shrink-0 rounded-full', m.read ? 'bg-transparent' : 'bg-brand-600']" :aria-label="m.read ? undefined : 'Unread'" />
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-ink">{{ m.subject }}</p>
                    <p class="text-sm text-ink-soft">{{ m.body }}</p>
                    <p class="mt-1 text-xs text-muted">{{ when(m.at) }}</p>
                </div>
                <Link v-if="m.link" :href="m.link" class="self-center text-sm font-medium text-brand-700 hover:underline" @click="open(m)">Open</Link>
            </li>
        </ul>
        <PaginationNav v-if="messages.last_page > 1" class="mt-4" :links="messages.links" :from="messages.from" :to="messages.to" :total="messages.total" />
    </AppLayout>
</template>
