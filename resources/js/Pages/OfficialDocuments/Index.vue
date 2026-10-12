<script setup lang="ts">
import DataTable from '@/Components/DataTable.vue';
import PageHeader from '@/Components/PageHeader.vue';
import StatusBadge from '@/Components/StatusBadge.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { date } from '@/Workflow/format';
import { Link, usePage } from '@inertiajs/vue3';
import { Download } from 'lucide-vue-next';
import { computed } from 'vue';
import { docTone, type OfficialDocumentRow } from './types';

const props = defineProps<{ documents: OfficialDocumentRow[] }>();
const external = computed(() => ['vendor', 'external'].includes(usePage().props.auth.user!.category));
const mine = computed(() => props.documents.filter((d) => d.mine));
const others = computed(() => props.documents.filter((d) => !d.mine));
</script>

<template>
    <AppLayout title="Official documents">
        <PageHeader title="Official documents" description="The institute's official forms, filled from your approved requests. A document becomes available only once every required approval is complete." />

        <DataTable v-for="[title, rows] in ([['My requests', mine], ['Requests I decided on', others]] as const)" :key="title" :title="title" :empty="!rows.length" :empty-title="title === 'My requests' ? 'No official documents yet' : 'None'" class="mb-6" v-show="title === 'My requests' || rows.length">
            <table class="data-table">
                <thead><tr><th>Application</th><th>Document</th><th>Submitted</th><th>Approved</th><th>Request</th><th>Document status</th><th><span class="sr-only">Action</span></th></tr></thead>
                <tbody>
                    <tr v-for="d in rows" :key="d.uuid">
                        <td>
                            <Link v-if="!external" :href="route('requests.show', d.instance_id)" class="font-mono text-sm text-brand-700 hover:underline">{{ d.reference }}</Link>
                            <span v-else class="font-mono text-sm">{{ d.reference }}</span>
                            <p class="text-xs text-muted">{{ d.workflow }}</p>
                        </td>
                        <td>{{ d.name }}<p v-if="d.template_version" class="text-xs text-muted">form version {{ d.template_version }}<span v-if="d.document_version > 1"> · reissue {{ d.document_version }}</span></p></td>
                        <td class="text-sm">{{ d.submitted_at ? date(d.submitted_at) : '—' }}</td>
                        <td class="text-sm">{{ d.approved_at ? date(d.approved_at) : '—' }}</td>
                        <td><StatusBadge :status="d.instance_status" /></td>
                        <td><StatusBadge :status="docTone(d)" :label="d.status_label" /></td>
                        <td class="text-right whitespace-nowrap">
                            <a v-if="d.download" :href="d.download" class="inline-flex items-center gap-1 text-sm font-medium text-brand-700 hover:underline"><Download :size="15" aria-hidden="true" /> Download PDF</a>
                            <span v-else class="text-xs text-muted">Not available</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </DataTable>
    </AppLayout>
</template>
