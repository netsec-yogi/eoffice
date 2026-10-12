<script setup lang="ts">
import { Handle, Position } from '@vue-flow/core';
import { Bot, Clock, Flag, GitBranch, GitFork, GitMerge, Mail, PenLine, Play, ScanSearch, ShieldCheck, UserCheck, UsersRound, type LucideIcon } from 'lucide-vue-next';
import { NODE_META } from './catalogue';

const props = defineProps<{ data: { type: string; name: string; problems?: number; summary?: string }; selected?: boolean }>();
const icons: Record<string, LucideIcon> = {
    start: Play, approval: UserCheck, review: ScanSearch, verification: ShieldCheck, committee: UsersRound, decision: GitBranch,
    parallel_split: GitFork, parallel_join: GitMerge, signature: PenLine, automation: Bot, notification: Mail, timer: Clock, end: Flag,
};
</script>

<template>
    <div :class="['w-56 rounded-2xl bg-surface shadow-card ring-1 transition', selected ? 'ring-2 ring-brand-500' : props.data.problems ? 'ring-2 ring-red-400' : 'ring-line']">
        <Handle v-if="data.type !== 'start'" type="target" :position="Position.Top" class="!size-3 !border-2 !border-surface !bg-line-strong" />
        <div class="flex items-center gap-2.5 p-3">
            <span :class="['grid size-8 shrink-0 place-items-center rounded-xl text-white', NODE_META[data.type]?.tone ?? 'bg-slate-500']">
                <component :is="icons[data.type] ?? Flag" :size="16" aria-hidden="true" />
            </span>
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-ink">{{ data.name }}</p>
                <p class="truncate text-[11px] text-muted">{{ data.summary ?? NODE_META[data.type]?.label }}</p>
            </div>
        </div>
        <p v-if="data.problems" class="border-t border-red-100 px-3 py-1 text-[11px] font-medium text-red-700">{{ data.problems }} problem{{ data.problems > 1 ? 's' : '' }}</p>
        <Handle v-if="data.type !== 'end'" type="source" :position="Position.Bottom" class="!size-3 !border-2 !border-surface !bg-brand-500" />
    </div>
</template>
