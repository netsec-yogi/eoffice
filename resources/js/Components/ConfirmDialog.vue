<script setup lang="ts">
import { TriangleAlert } from 'lucide-vue-next';
import AppButton from './AppButton.vue';
import ModalDialog from './ModalDialog.vue';

/** "Are you sure?" for destructive or irreversible actions. */
withDefaults(
    defineProps<{ show: boolean; title: string; message?: string; confirmLabel?: string; cancelLabel?: string; tone?: 'danger' | 'primary'; loading?: boolean }>(),
    { confirmLabel: 'Confirm', cancelLabel: 'Cancel', tone: 'danger' },
);
const emit = defineEmits<{ confirm: []; close: [] }>();
</script>

<template>
    <ModalDialog :show="show" :title="title" size="sm" @close="emit('close')">
        <div class="flex gap-3">
            <span v-if="tone === 'danger'" class="grid size-9 shrink-0 place-items-center rounded-full bg-red-50 text-red-600 dark:bg-red-500/15"><TriangleAlert :size="18" /></span>
            <div class="text-sm text-muted"><slot>{{ message || 'Please confirm you want to continue.' }}</slot></div>
        </div>
        <template #footer>
            <AppButton variant="secondary" autofocus @click="emit('close')">{{ cancelLabel }}</AppButton>
            <AppButton :variant="tone" :loading="loading" @click="emit('confirm')">{{ confirmLabel }}</AppButton>
        </template>
    </ModalDialog>
</template>
