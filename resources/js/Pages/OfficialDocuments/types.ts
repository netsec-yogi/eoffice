export interface OfficialDocumentRow {
    uuid: string;
    name: string;
    status: string;
    status_label: string;
    reference: string | null;
    workflow: string;
    instance_id: number;
    instance_status: string;
    submitted_at: string | null;
    approved_at: string | null;
    generated_at: string | null;
    template_version: number | null;
    document_version: number;
    mine: boolean;
    download: string | null;
}

/** Badge tone for a document status (never colour alone: the label carries the meaning). */
export const docTone = (d: OfficialDocumentRow) =>
    ({ generated: 'completed', queued: 'pending', generating: 'pending', waiting_approval: d.instance_status === 'returned' ? 'returned' : 'in_review', awaiting_signature: 'pending', failed: 'failed', not_generated: 'cancelled', superseded: 'archived' })[d.status] ?? d.status;
