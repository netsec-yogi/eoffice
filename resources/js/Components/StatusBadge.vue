<script setup lang="ts">
import { Ban, CircleCheck, CircleDashed, CircleDot, CircleX, Clock, FilePen, ShieldCheck } from 'lucide-vue-next';
import { computed, type Component } from 'vue';

/**
 * One badge for every workflow state. Colour carries the tone, the icon
 * and label carry the meaning, so status is never colour-alone.
 */
const props = defineProps<{ status: string; label?: string; icon?: boolean }>();

type Tone = 'success' | 'warning' | 'danger' | 'neutral' | 'info';
const tones: Record<string, Tone> = {
    active: 'success', verified: 'success', approved: 'success', published: 'success', completed: 'success', paid: 'success',
    confirmed: 'success', accepted: 'success', open: 'success', live: 'success', sent: 'success', checked_in: 'success', resolved: 'success',
    pending: 'warning', pending_approval: 'warning', awaiting_verification: 'warning', submitted: 'warning', waitlisted: 'warning',
    payment_pending: 'warning', scheduled: 'warning', in_review: 'warning', upcoming: 'info', setting_up: 'warning',
    rejected: 'danger', failed: 'danger', suspended: 'danger', locked: 'danger', declined: 'danger', removed: 'danger', refunded: 'danger',
    draft: 'neutral', inactive: 'neutral', deactivated: 'neutral', archived: 'neutral', cancelled: 'neutral', closed: 'neutral', expired: 'neutral', off: 'neutral',
};
const tone = computed<Tone>(() => tones[props.status] ?? 'info');

const icons: Record<Tone, Component> = { success: CircleCheck, warning: Clock, danger: CircleX, neutral: CircleDashed, info: CircleDot };
const iconFor = computed<Component>(() =>
    props.status === 'verified' ? ShieldCheck : props.status === 'draft' ? FilePen : ['suspended', 'locked'].includes(props.status) ? Ban : icons[tone.value],
);

const classes: Record<Tone, string> = {
    success: 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300',
    warning: 'bg-amber-50 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
    danger: 'bg-red-50 text-red-700 dark:bg-red-500/15 dark:text-red-300',
    neutral: 'bg-surface-sunken text-muted',
    info: 'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-300',
};
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap capitalize', classes[tone]]">
        <component :is="iconFor" v-if="icon !== false" :size="12" :stroke-width="2.25" aria-hidden="true" />
        {{ label ?? status.replace(/_/g, ' ') }}
    </span>
</template>
