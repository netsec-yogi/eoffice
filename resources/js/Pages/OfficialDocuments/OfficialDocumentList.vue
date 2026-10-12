<script setup lang="ts">
import StatusBadge from '@/Components/StatusBadge.vue';
import { Download, FileCheck2 } from 'lucide-vue-next';
import { docTone, type OfficialDocumentRow } from './types';

defineProps<{ documents: OfficialDocumentRow[]; showReference?: boolean }>();
</script>

<template>
    <ul class="divide-y divide-line-soft">
        <li v-for="d in documents" :key="d.uuid" class="flex flex-wrap items-center gap-3 py-2.5">
            <FileCheck2 :size="18" class="shrink-0 text-muted" aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <p class="truncate font-medium text-ink">{{ d.name }}<span v-if="d.document_version > 1" class="font-normal text-muted"> (reissue {{ d.document_version }})</span></p>
                <p v-if="showReference" class="truncate text-xs text-muted">{{ d.reference }} · {{ d.workflow }}</p>
            </div>
            <StatusBadge :status="docTone(d)" :label="d.status_label" />
            <a v-if="d.download" :href="d.download" class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-sm font-medium text-brand-700 hover:bg-brand-50 dark:hover:bg-brand-500/10">
                <Download :size="15" aria-hidden="true" /> Download
            </a>
        </li>
    </ul>
</template>
