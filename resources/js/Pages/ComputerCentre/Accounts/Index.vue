<script setup lang="ts">
import AlertBox from '@/Components/AlertBox.vue';
import CheckboxInput from '@/Components/CheckboxInput.vue';
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import PaginationNav from '@/Components/PaginationNav.vue';
import SelectInput from '@/Components/SelectInput.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import TextInput from '@/Components/TextInput.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { Paginated } from '@/types';
import { date } from '@/Workflow/format';
import { Link, router } from '@inertiajs/vue3';
import { Search } from 'lucide-vue-next';
import { reactive, watch } from 'vue';

interface Row { id: number; kind: string; identifier: string; status: string; provisioned_by: string; workflow_instance_id: number | null; user: { name: string; username: string; category: string }; expires_on: string | null; provisioned_at: string; deprovisioned_at: string | null }
const props = defineProps<{ accounts: Paginated<Row>; filters: Record<string, string | boolean>; integrations: Record<string, string> }>();

const f = reactive({ q: '', kind: '', status: '', expiring: false, ...props.filters });
let t: ReturnType<typeof setTimeout>;
watch(f, () => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('cc.accounts.index'), Object.fromEntries(Object.entries({ ...f, expiring: f.expiring ? 1 : '' }).filter(([, v]) => v)), { preserveState: true, replace: true }), 300);
});
const off = Object.entries(props.integrations).filter(([, d]) => d === 'none').map(([k]) => k);
</script>

<template>
    <AppLayout title="Access ledger">
        <PageHeader title="Access ledger" description="Every mailbox, Wi-Fi/VPN entitlement and VM granted through a workflow, whether by automation or by hand. Deprovisioning works from this list." />
        <AlertBox v-if="off.length" tone="info" class="mb-4">Not connected to an external system: {{ off.join(', ') }}. Those steps arrive as manual tasks for the Computer Centre and are recorded here when completed.</AlertBox>
        <DataTable :empty="!accounts.data.length" empty-title="Nothing recorded">
            <template #toolbar>
                <div class="grid w-full items-center gap-2 sm:grid-cols-4">
                    <TextInput v-model="f.q" :icon="Search" placeholder="Person or identifier" aria-label="Search" />
                    <SelectInput v-model="f.kind" :options="['email', 'wifi', 'vpn', 'vm'].map((k) => ({ value: k, label: k }))" placeholder="Any kind" aria-label="Kind" />
                    <SelectInput v-model="f.status" :options="['active', 'suspended', 'deprovisioned'].map((k) => ({ value: k, label: k }))" placeholder="Any status" aria-label="Status" />
                    <CheckboxInput v-model="f.expiring" label="Expiring within 30 days" />
                </div>
            </template>
            <table class="data-table">
                <thead><tr><th>Person</th><th>Kind</th><th>Identifier</th><th>Granted</th><th>Expires</th><th>Status</th></tr></thead>
                <tbody>
                    <tr v-for="a in accounts.data" :key="a.id">
                        <td>{{ a.user.name }}<p class="text-xs text-muted">{{ a.user.username }} · {{ a.user.category }}</p></td>
                        <td class="uppercase">{{ a.kind }}</td>
                        <td class="max-w-xs truncate font-mono text-xs" :title="a.identifier">{{ a.identifier }}</td>
                        <td class="text-xs">{{ date(a.provisioned_at) }} · {{ a.provisioned_by }}<template v-if="a.workflow_instance_id"> · <Link :href="route('requests.show', a.workflow_instance_id)" class="text-brand-700 hover:underline">request</Link></template></td>
                        <td class="text-xs">{{ a.expires_on ?? '—' }}</td>
                        <td><StatusBadge :status="a.status === 'deprovisioned' ? 'archived' : a.status === 'suspended' ? 'suspended' : 'active'" :label="a.status" /></td>
                    </tr>
                </tbody>
            </table>
            <template #footer><PaginationNav :links="accounts.links" :from="accounts.from" :to="accounts.to" :total="accounts.total" /></template>
        </DataTable>
    </AppLayout>
</template>
