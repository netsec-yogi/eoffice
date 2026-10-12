<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { CircleCheck, CircleX, SearchX } from 'lucide-vue-next';

defineProps<{ certificate: { number: string; kind: string; holder: string; issued_on: string; signatory: string; valid: boolean } | null }>();
</script>

<template>
    <GuestLayout title="Certificate verification">
        <h1 class="text-lg font-bold text-ink">Certificate verification</h1>
        <div v-if="!certificate" class="mt-4 flex items-start gap-3 rounded-xl bg-surface-muted p-4">
            <SearchX :size="22" class="shrink-0 text-muted" aria-hidden="true" />
            <p class="text-sm text-ink-soft">No certificate has this verification code. Check the code printed at the foot of the document.</p>
        </div>
        <template v-else>
            <div :class="['mt-4 flex items-center gap-3 rounded-xl p-4', certificate.valid ? 'bg-emerald-50 dark:bg-emerald-500/10' : 'bg-red-50 dark:bg-red-500/10']" role="status">
                <component :is="certificate.valid ? CircleCheck : CircleX" :size="24" :class="certificate.valid ? 'text-emerald-600' : 'text-red-600'" aria-hidden="true" />
                <p :class="['font-semibold', certificate.valid ? 'text-emerald-800 dark:text-emerald-300' : 'text-red-700 dark:text-red-300']">{{ certificate.valid ? 'Genuine and valid' : 'Revoked: this certificate is no longer valid' }}</p>
            </div>
            <dl class="mt-4 space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-muted">Number</dt><dd class="font-mono text-ink">{{ certificate.number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Certificate</dt><dd class="text-ink">{{ certificate.kind }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Issued to</dt><dd class="text-ink">{{ certificate.holder }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Issued on</dt><dd class="text-ink">{{ certificate.issued_on }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-muted">Approved by</dt><dd class="text-ink">{{ certificate.signatory }}</dd></div>
            </dl>
            <p class="mt-4 text-xs text-subtle">Compare these details with the document you hold. Only the details needed for verification are shown.</p>
        </template>
    </GuestLayout>
</template>
