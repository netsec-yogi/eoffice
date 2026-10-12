<script setup lang="ts">
import { pending, settle } from '@/lib/confirm';
import ConfirmDialog from './ConfirmDialog.vue';
</script>

<template>
    <ConfirmDialog
        :show="pending.request !== null"
        :title="pending.request?.title ?? ''"
        :message="pending.request?.message"
        :confirm-label="pending.request?.confirmLabel"
        :cancel-label="pending.request?.cancelLabel"
        :tone="pending.request?.tone"
        @confirm="settle(true)"
        @close="settle(false)"
    />
</template>
