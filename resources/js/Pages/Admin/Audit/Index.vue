<script setup lang="ts">
import AppButton from '@/Components/AppButton.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { router } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import { reactive, ref, watch } from 'vue';

interface Event { id: number; occurred_at: string; actor: string | null; actor_id: number | null; action: string; subject: string | null; outcome: string; ip_address: string | null; correlation_id: string | null; context: Record<string, unknown> | null; hash: string }

const props = defineProps<{ events: Paginated<Event>; filters: Record<string, string>; subjectTypes: string[] }>();

const f = reactive({ action: '', subject_type: '', subject_id: '', outcome: '', from: '', to: '', correlation: '', ...props.filters });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('admin.audit.index'), Object.fromEntries(Object.entries(f).filter(([, v]) => v !== '' && v != null)), { preserveState: true, replace: true }), 350);
});
const open = ref<number | null>(null);
const verifying = ref(false);
const verify = () => router.post(route('admin.audit.verify'), {}, { preserveScroll: true, onStart: () => (verifying.value = true), onFinish: () => (verifying.value = false) });
const tone = (o: string) => (o === 'success' ? 'completed' : o === 'denied' ? 'rejected' : 'failed');
</script>

<template>
    <AppLayout title="Audit log">
        <PageHeader title="Audit log" description="Append-only and hash-chained. Entries cannot be edited from the application; verification recomputes the chain.">
            <AppButton :icon="ShieldCheck" variant="outline" :loading="verifying" @click="verify">Verify chain</AppButton>
        </PageHeader>

        <DataTable :empty="!events.data.length" empty-title="No events match">
            <template #toolbar>
                <div class="grid w-full gap-2 sm:grid-cols-3 lg:grid-cols-6">
                    <TextInput v-model="f.action" placeholder="Action prefix, e.g. workflow." aria-label="Action" />
                    <SelectInput v-model="f.subject_type" :options="subjectTypes.map((s) => ({ value: s, label: s }))" placeholder="Any subject" aria-label="Subject type" />
                    <TextInput v-model="f.subject_id" type="number" placeholder="Subject ID" aria-label="Subject ID" />
                    <SelectInput v-model="f.outcome" :options="['success', 'failure', 'denied'].map((o) => ({ value: o, label: o }))" placeholder="Any outcome" aria-label="Outcome" />
                    <TextInput v-model="f.from" type="date" aria-label="From date" />
                    <TextInput v-model="f.to" type="date" aria-label="To date" />
                </div>
            </template>
            <table class="data-table">
                <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Subject</th><th>Outcome</th><th>Details</th></tr></thead>
                <tbody>
                    <template v-for="e in events.data" :key="e.id">
                        <tr>
                            <td class="text-xs whitespace-nowrap">{{ new Date(e.occurred_at).toLocaleString() }}</td>
                            <td class="text-xs">{{ e.actor ?? '—' }}</td>
                            <td class="font-mono text-xs">{{ e.action }}</td>
                            <td class="font-mono text-xs">{{ e.subject ?? '—' }}</td>
                            <td><StatusBadge :status="tone(e.outcome)" :label="e.outcome" /></td>
                            <td><button v-if="e.context || e.correlation_id" class="text-xs font-medium text-brand-600 hover:underline" :aria-expanded="open === e.id" @click="open = open === e.id ? null : e.id">{{ open === e.id ? 'Hide' : 'Show' }}</button></td>
                        </tr>
                        <tr v-if="open === e.id">
                            <td colspan="6" class="bg-surface-muted">
                                <dl class="grid gap-1 text-xs sm:grid-cols-[10rem_1fr]">
                                    <dt class="text-muted">Correlation</dt><dd class="font-mono">{{ e.correlation_id ?? '—' }}</dd>
                                    <dt class="text-muted">IP address</dt><dd class="font-mono">{{ e.ip_address ?? '—' }}</dd>
                                    <dt class="text-muted">Hash</dt><dd class="font-mono">{{ e.hash }}…</dd>
                                    <dt class="text-muted">Context</dt><dd><pre class="overflow-x-auto font-mono whitespace-pre-wrap">{{ JSON.stringify(e.context, null, 2) }}</pre></dd>
                                </dl>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="events.links" :from="events.from" :to="events.to" :total="events.total" /></template>
        </DataTable>
    </AppLayout>
</template>
