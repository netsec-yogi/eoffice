import type { AuthUser } from '@/types';
import {
    Archive,
    FileCheck2,
    FileCog,
    Banknote,
    Landmark,
    ShoppingCart,
    Store,
    BadgeCheck,
    CalendarCheck,
    Megaphone,
    ReceiptIndianRupee,
    Scale,
    BellRing,
    FolderOpen,
    Mailbox,
    KeySquare,
    Laptop,
    Network,
    Building2,
    CalendarDays,
    FilePlus2,
    FileStack,
    FormInput,
    GitBranch,
    House,
    Inbox,
    KeyRound,
    LayoutDashboard,
    ScrollText,
    ShieldCheck,
    UserCheck,
    Users,
    UsersRound,
    type LucideIcon,
} from 'lucide-vue-next';

/**
 * The app's navigation, defined once. AppLayout renders it; breadcrumbs
 * derive from it. Items whose route does not exist yet, or that the user
 * may not use, are filtered out, so the menu never links to a 403 or 404.
 * (Hiding is cosmetic: every endpoint authorises on the server.)
 */
export interface NavLeaf {
    label: string;
    route: string;
    /** route().current() pattern for the active state; defaults to the route name. */
    match?: string;
    badge?: string;
    show?: boolean;
}

export interface NavItem extends NavLeaf {
    icon: LucideIcon;
    children?: NavLeaf[];
}

export interface NavSection {
    title: string;
    items: NavItem[];
}

export function buildNavigation(user: AuthUser): NavSection[] {
    const can = (p: string) => user.permissions.includes(p);

    // Vendors see only their portal: never internal pages.
    if (user.category === 'vendor') {
        return [{ title: 'Vendor', items: [{ label: 'Vendor portal', route: 'vendor.portal', icon: Store }, { label: 'Official documents', route: 'official-documents.index', icon: FileCheck2 }].filter((i) => route().has(i.route)) }];
    }

    const sections: NavSection[] = [
        {
            title: 'Work',
            items: [
                { label: 'Dashboard', route: 'dashboard', icon: House },
                { label: 'Inbox', route: 'tasks.index', match: 'tasks.*', icon: Inbox, badge: 'tasks' },
                { label: 'New request', route: 'requests.catalogue', match: 'requests.catalogue', icon: FilePlus2 },
                { label: 'My requests', route: 'requests.index', match: '(requests.index|requests.edit)', icon: FileStack },
                { label: 'Official documents', route: 'official-documents.index', icon: FileCheck2 },
                { label: 'Delegations', route: 'delegations.index', match: 'delegations.*', icon: UserCheck },
                { label: 'Monitor', route: 'monitor.index', match: 'monitor.*', icon: LayoutDashboard, show: can('workflows.monitor') || can('workflows.monitor.department') },
            ],
        },
        {
            title: 'Finance & purchase',
            items: [
                { label: 'Purchases', route: 'procurement.index', match: 'procurement.*', icon: ShoppingCart },
                { label: 'Advances', route: 'advances.index', icon: Banknote },
                { label: 'Budget', route: 'budget.index', match: 'budget.*', icon: Landmark, show: can('budget.view') },
                { label: 'Vendors', route: 'vendors.index', icon: Store, show: can('procurement.manage') },
            ],
        },
        {
            title: 'Registrar',
            items: [
                { label: 'My leave', route: 'leave.mine', icon: CalendarCheck },
                { label: 'Travel & claims', route: 'claims.index', icon: ReceiptIndianRupee },
                { label: 'Certificates', route: 'certificates.index', icon: BadgeCheck },
                { label: 'Circulars', route: 'circulars.index', match: 'circulars.*', icon: Megaphone, badge: 'circulars' },
                { label: 'Leave administration', route: 'leave.admin', icon: CalendarCheck, show: can('registrar.manage') },
                { label: 'RTI register', route: 'rti.index', icon: Scale, show: can('rti.manage') },
            ],
        },
        {
            title: 'e-Office',
            items: [
                { label: 'Files', route: 'efiles.index', match: 'efiles.*', icon: FolderOpen, badge: 'files' },
                { label: 'Correspondence', route: 'correspondence.index', icon: Mailbox },
                { label: 'Records', route: 'records.index', icon: Archive, show: can('efiles.records') },
            ],
        },
        {
            title: 'Computer Centre',
            items: [
                { label: 'IT assets', route: 'cc.assets.index', match: 'cc.assets.*', icon: Laptop, show: can('computer_centre.manage') },
                { label: 'Software licences', route: 'cc.licences.index', icon: KeySquare, show: can('computer_centre.manage') },
                { label: 'Access ledger', route: 'cc.accounts.index', icon: Network, show: can('computer_centre.manage') },
            ],
        },
        {
            title: 'Administration',
            items: [
                { label: 'Workflows', route: 'admin.workflows.index', match: 'admin.workflows.*', icon: GitBranch, show: can('workflows.design') },
                { label: 'Official PDF templates', route: 'admin.pdf-templates.index', match: 'admin.pdf-templates.*', icon: FileStack, show: can('official_templates.manage') },
                { label: 'Document generation', route: 'admin.official-documents.index', icon: FileCog, show: can('official_documents.manage') },
                { label: 'Forms', route: 'admin.forms.index', match: 'admin.forms.*', icon: FormInput, show: can('workflows.design') },
                { label: 'Users', route: 'admin.users.index', match: 'admin.users.*', icon: Users, show: can('users.view') },
                { label: 'Organisation', route: 'admin.organisation.index', icon: Building2, show: can('organisation.manage') },
                { label: 'Authorities', route: 'admin.authorities.index', icon: KeyRound, show: can('organisation.manage') },
                { label: 'Committees', route: 'admin.committees.index', icon: UsersRound, show: can('committees.manage') },
                { label: 'Roles', route: 'admin.roles.index', icon: ShieldCheck, show: can('roles.manage') },
                { label: 'Calendars', route: 'admin.calendars.index', icon: CalendarDays, show: can('calendars.manage') },
                { label: 'Notifications', route: 'admin.notifications.index', icon: BellRing, show: can('notifications.manage') },
                { label: 'Audit log', route: 'admin.audit.index', icon: ScrollText, show: can('audit.view') },
            ],
        },
    ];

    return sections
        .map((s) => ({ ...s, items: s.items.filter((i) => i.show !== false && route().has(i.route)) }))
        .filter((s) => s.items.length > 0);
}

export const isActive = (item: NavLeaf) => !!route().current(item.match ?? item.route);

/** The nav path to the current page, for breadcrumbs. */
export function currentTrail(sections: NavSection[]): NavLeaf[] {
    for (const section of sections) {
        for (const item of section.items) {
            if (isActive(item)) return [{ label: section.title, route: '' }, item].filter((c) => c.route);
        }
    }
    return [];
}
