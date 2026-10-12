<script setup lang="ts">
import { ArrowRightLeft, Ban, Bot, CircleCheck, CircleX, Clock, Flag, Pause, Play, Send, Siren, Undo2, UserPlus, type LucideIcon } from 'lucide-vue-next';
import { date } from './format';

export interface TimelineEntry {
    id: number;
    action: string;
    node: string | null;
    actor: string | null;
    on_behalf_of: string | null;
    remarks: string | null;
    data: Record<string, unknown> | null;
    at: string;
}
defineProps<{ entries: TimelineEntry[] }>();

const meta: Record<string, [string, LucideIcon, string]> = {
    submitted: ['Submitted', Send, 'text-brand-600'],
    resubmitted: ['Resubmitted', Send, 'text-brand-600'],
    draft_saved: ['Draft saved', Clock, 'text-muted'],
    assigned: ['Assigned', UserPlus, 'text-muted'],
    approved: ['Approved', CircleCheck, 'text-emerald-600'],
    rejected: ['Rejected', CircleX, 'text-red-600'],
    abstained: ['Abstained', Clock, 'text-muted'],
    returned: ['Returned to initiator', Undo2, 'text-amber-600'],
    forwarded: ['Forwarded', ArrowRightLeft, 'text-brand-600'],
    reassigned: ['Reassigned', ArrowRightLeft, 'text-amber-600'],
    escalated: ['Escalated', Siren, 'text-red-600'],
    held: ['Put on hold', Pause, 'text-amber-600'],
    resumed: ['Resumed', Play, 'text-brand-600'],
    cancelled: ['Cancelled', Ban, 'text-muted'],
    completed: ['Completed – approved', Flag, 'text-emerald-600'],
    automation_queued: ['Automatic step started', Bot, 'text-muted'],
    automation_succeeded: ['Automatic step done', Bot, 'text-emerald-600'],
    automation_failed: ['Automatic step failed', Bot, 'text-red-600'],
    automation_blocked: ['Automatic step needs a person', Bot, 'text-amber-600'],
    completed_manually: ['Completed by hand', CircleCheck, 'text-emerald-600'],
    failed_manually: ['Marked as failed', CircleX, 'text-red-600'],
    timer_fired: ['Waiting period over', Clock, 'text-muted'],
};
const m = (a: string) => meta[a] ?? [a.replace(/_/g, ' '), Clock, 'text-muted'];

function detail(e: TimelineEntry): string | null {
    const d = e.data ?? {};
    if (e.action === 'assigned') return `To ${d.assignee_name}${d.on_behalf_of ? ` (for ${d.on_behalf_of})` : ''} · ${d.via}`;
    if (e.action === 'forwarded' || e.action === 'reassigned') return `To ${d.to_name}`;
    if (e.action === 'escalated') return `Level ${d.level} → ${(d.to as string[] | undefined)?.join(', ')}`;
    if (e.action === 'returned' && d.kind === 'documents') return 'Additional documents requested';
    if (e.action === 'submitted' || e.action === 'resubmitted') return d.revision ? `Revision ${d.revision}` : null;
    return null;
}
</script>

<template>
    <ol class="relative space-y-4 border-l border-line pl-6">
        <li v-for="e in entries" :key="e.id" class="relative">
            <span class="absolute top-0.5 -left-[33px] grid size-5 place-items-center rounded-full bg-surface ring-2 ring-surface">
                <component :is="m(e.action)[1]" :size="15" :class="m(e.action)[2]" aria-hidden="true" />
            </span>
            <p class="text-sm">
                <span class="font-semibold text-ink">{{ m(e.action)[0] }}</span>
                <span v-if="e.node" class="text-muted"> · {{ e.node }}</span>
            </p>
            <p class="text-xs text-muted">
                <template v-if="e.actor">{{ e.actor }}<template v-if="e.on_behalf_of"> for {{ e.on_behalf_of }}</template> · </template>{{ date(e.at) }}
            </p>
            <p v-if="detail(e)" class="mt-0.5 text-xs text-muted">{{ detail(e) }}</p>
            <p v-if="e.remarks" class="mt-1.5 rounded-xl bg-surface-muted px-3 py-2 text-sm whitespace-pre-line text-ink-soft">{{ e.remarks }}</p>
        </li>
    </ol>
</template>
