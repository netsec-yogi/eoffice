export interface AuthUser {
    id: number;
    name: string;
    username: string;
    email: string | null;
    category: string;
    department: { id: number; code: string; name: string } | null;
    designation: string | null;
    roles: string[];
    permissions: string[];
    can_admin: boolean;
}

export interface Flash {
    success?: string | null;
    warning?: string | null;
    error?: string | null;
}

export interface SharedProps {
    appName: string;
    auth: { user: AuthUser | null };
    counts: Record<string, number> | null;
    flash: Flash;
    errors: Record<string, string>;
    [key: string]: unknown;
}

export interface PaginationLink {
    url: string | null;
    label: string;
    active: boolean;
}

export interface Paginated<T> {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
}

export interface Option<V = string> {
    value: V;
    label: string;
}

export interface UserRef {
    id: number;
    name: string;
    username: string;
    department?: string | null;
    designation?: string | null;
}
