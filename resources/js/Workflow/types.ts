import type { Rule } from './rules';

export interface FieldOption {
    value: string;
    label: string;
}

export interface FormField {
    key: string;
    type: string;
    label: string;
    help?: string;
    placeholder?: string;
    required?: boolean;
    required_if?: Rule | null;
    visible_if?: Rule | null;
    default?: unknown;
    min?: number | string;
    max?: number | string;
    min_length?: number;
    max_length?: number;
    pattern?: string;
    options?: FieldOption[];
    /** Registered live option list; the server fills `options` from it. */
    source?: string;
    accept?: string[];
    max_files?: number;
    max_kb?: number;
    columns?: FormField[];
    min_rows?: number;
    max_rows?: number;
    formula?: string;
    category?: string;
    content?: string;
    editable_at?: string[];
}

export interface FormSection {
    key: string;
    title: string;
    description?: string;
    visible_if?: Rule | null;
    fields: FormField[];
}

export interface FormSchema {
    sections: FormSection[];
}

export interface DocumentSummary {
    id: string;
    name: string;
    mime_type: string;
    size: number;
    scan_status: string;
    url: string;
    preview: boolean;
    version?: number;
    uploaded_at?: string;
    category?: string;
    classification?: string;
}
