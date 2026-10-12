import type { FormSchema } from '../types';

export interface Catalogue {
    node_types: string[];
    authorities: { code: string; name: string; scope: 'institute' | 'department' }[];
    committees: { code: string; name: string }[];
    roles: string[];
    actions: Record<string, { label: string; settings: Record<string, { label: string; type?: string; required?: boolean }> }>;
    templates: string[];
    committee_rules: string[];
    units: string[];
    forms: { id: number; label: string; schema: FormSchema }[];
    signature_provider: boolean;
    validators: { key: string; label: string; provides: string[] }[];
    departments: { id: number; code: string; name: string }[];
}

export const NODE_META: Record<string, { label: string; hint: string; tone: string }> = {
    start: { label: 'Start', hint: 'The request is submitted', tone: 'bg-slate-500' },
    approval: { label: 'Approval', hint: 'Someone approves or rejects', tone: 'bg-brand-600' },
    review: { label: 'Review', hint: 'Someone examines and recommends', tone: 'bg-sky-600' },
    verification: { label: 'Verification', hint: 'Someone checks documents or facts', tone: 'bg-teal-600' },
    committee: { label: 'Committee', hint: 'Members vote under a stated rule', tone: 'bg-violet-600' },
    decision: { label: 'Decision', hint: 'Route by conditions on the form', tone: 'bg-amber-500' },
    parallel_split: { label: 'Parallel start', hint: 'Run branches at the same time', tone: 'bg-orange-500' },
    parallel_join: { label: 'Parallel join', hint: 'Wait for the branches', tone: 'bg-orange-500' },
    signature: { label: 'Signature', hint: 'Sign with eSign / DSC', tone: 'bg-pink-600' },
    automation: { label: 'Automation', hint: 'A system does something', tone: 'bg-emerald-600' },
    notification: { label: 'Notification', hint: 'Send a message', tone: 'bg-cyan-600' },
    timer: { label: 'Wait', hint: 'Pause for a period', tone: 'bg-stone-500' },
    end: { label: 'End', hint: 'Finish with an outcome', tone: 'bg-slate-700' },
};

export const HUMAN = ['approval', 'review', 'verification', 'committee', 'signature'];

export function outcomesFor(type: string): { value: string; label: string }[] {
    if (HUMAN.includes(type)) return [{ value: 'approve', label: 'Approved' }, { value: 'reject', label: 'Rejected' }];
    if (type === 'automation') return [{ value: 'success', label: 'Succeeded' }, { value: 'failure', label: 'Failed' }];
    return [{ value: 'default', label: 'Next' }];
}
