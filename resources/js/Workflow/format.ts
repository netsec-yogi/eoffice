export interface InstanceSummary {
    id: number;
    reference: string | null;
    title: string;
    status: string;
    outcome: string | null;
    workflow: string | null;
    initiator: string | null;
    department: string | null;
    submitted_at: string | null;
    completed_at: string | null;
    updated_at: string | null;
    current: { name: string; assignee: string | null; due_at: string | null; overdue: boolean }[];
}

const labels: Record<string, string> = {
    draft: 'Draft',
    in_progress: 'In progress',
    returned: 'Returned',
    on_hold: 'On hold',
    completed: 'Approved',
    rejected: 'Not approved',
    cancelled: 'Cancelled',
};
export const statusLabel = (s: string) => labels[s] ?? s.replace(/_/g, ' ');

export function when(iso: string | null | undefined): string {
    if (!iso) return '—';
    const d = new Date(iso);
    const diff = (Date.now() - d.getTime()) / 1000;
    if (Math.abs(diff) < 60) return 'just now';
    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
    const steps: [number, Intl.RelativeTimeFormatUnit][] = [[60, 'second'], [60, 'minute'], [24, 'hour'], [7, 'day'], [4.35, 'week'], [12, 'month'], [Infinity, 'year']];
    let value = -diff;
    for (const [size, unit] of steps) {
        if (Math.abs(value) < size) return rtf.format(Math.round(value), unit);
        value /= size;
    }
    return d.toLocaleDateString();
}

export const date = (iso: string | null | undefined) => (iso ? new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—');

export function currentStep(i: InstanceSummary): string {
    if (i.status === 'returned') return 'You (clarification)';
    if (!i.current.length) return '—';
    return i.current.map((c) => `${c.name}${c.assignee ? ` · ${c.assignee}` : ''}`).join('; ');
}
